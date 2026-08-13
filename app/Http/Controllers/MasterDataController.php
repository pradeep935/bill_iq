<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Branch;
use App\Models\HsnMaster;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\MasterDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MasterDataController extends Controller
{
    public function __construct(private MasterDataService $masters) {}

    public function index()
    {
        if ($redirect = AppController::guardPage('masters')) {
            return $redirect;
        }

        return Inertia::render('Setup/Masters', [
            'page' => 'masters',
            'title' => 'Masters',
            'initial_tab' => 'category',
        ]);
    }

    public function references()
    {
        return response()->json($this->masters->references());
    }

    public function list(Request $request, string $type)
    {
        [$model, $searchColumns] = $this->definition($type);
        $businessId = AppController::businessId();
        $status = $request->query('status');
        $search = trim((string) $request->query('search'));
        $perPage = max(10, min((int) $request->query('per_page', $type === 'hsn' ? 25 : 15), 100));

        $query = $model::query();
        $this->scopeBusiness($query, $model, $businessId);
        $this->scopeType($query, $type);

        if ($type === 'subcategory' && $request->filled('parent_id')) {
            $query->where('parent_id', $request->query('parent_id'));
        }

        if ($type === 'hsn') {
            if ($request->filled('code_type')) {
                $query->where('code_type', strtoupper((string) $request->query('code_type')));
            }

            if ($request->filled('taxability')) {
                $query->where('taxability', $request->query('taxability'));
            }

            if ($request->filled('verification_status')) {
                $verification = (string) $request->query('verification_status');

                if ($verification === 'verified') {
                    $query->where(function (Builder $inner) {
                        $inner->where('verification_status', 'verified')
                            ->orWhere('verification_status', 'classification_verified')
                            ->orWhere('verification_status', 'Classification Verified');
                    });
                } elseif ($verification === 'rate_verified') {
                    $query->where(function (Builder $inner) {
                        $inner->where('verification_status', 'verified')
                            ->orWhere('verification_status', 'rate_verified')
                            ->orWhere('verification_status', 'Rate Verified');
                    });
                } elseif ($verification === 'rate_suggested') {
                    $query->where(function (Builder $inner) {
                        $inner->where('verification_status', 'rate_suggested')
                            ->orWhere('verification_status', 'Rate Suggested');
                    });
                } else {
                    $query->where('verification_status', $verification);
                }
            }

            if ($request->filled('source')) {
                $request->query('source') === 'official'
                    ? $query->whereNull('business_id')
                    : $query->where('business_id', $businessId);
            }

            if ($request->filled('chapter_code')) {
                $query->where('chapter_code', str_pad((string) $request->query('chapter_code'), 2, '0', STR_PAD_LEFT));
            }

            if ($request->filled('gst_rate')) {
                $query->where('gst_rate', (float) $request->query('gst_rate'));
            }
        }

        if ($status) {
            $query->where('status', $status);
        }

        $searchColumns = array_values(array_filter(
            $searchColumns,
            fn (string $column) => Schema::hasColumn((new $model())->getTable(), $column)
        ));

        if ($search !== '' && $searchColumns) {
            $query->where(function (Builder $inner) use ($search, $searchColumns) {
                foreach ($searchColumns as $column) {
                    $inner->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        if ($type === 'hsn') {
            $summaryQuery = clone $query;
            $paginator = $query
                ->orderByRaw("CASE WHEN code_type = 'HSN' THEN 0 ELSE 1 END")
                ->orderBy('hsn_code')
                ->paginate($perPage)
                ->through(fn (HsnMaster $record) => $this->formatHsnRecord($record));

            return response()->json([
                'data' => $paginator,
                'summary' => $this->hsnSummary($summaryQuery, $businessId),
            ]);
        }

        if ($type === 'branch') {
            $paginator = $query->latest('id')->paginate($perPage)->through(fn (Branch $record) => $this->formatBranchRecord($record));

            return response()->json([
                'data' => $paginator,
            ]);
        }

        return response()->json([
            'data' => $query->latest('id')->paginate($perPage),
        ]);
    }

    public function store(Request $request, string $type)
    {
        [$model] = $this->definition($type);
        $data = $this->validated($request, $type);
        $record = new $model();
        $record->fill($this->payload($type, $data, $record))->save();

        return response()->json([
            'message' => 'Master record saved successfully.',
            'record' => $record->fresh(),
        ], 201);
    }

    public function update(Request $request, string $type, int $id)
    {
        [$model] = $this->definition($type);
        $record = $this->findScoped($model, $type, $id);

        if ($type === 'hsn' && $this->isOfficialHsn($record)) {
            if (!$request->boolean('rate_update_only')) {
                throw ValidationException::withMessages([
                    'hsn_code' => 'Official Government HSN/SAC classification cannot be edited. Only GST rate verification is allowed here.',
                ]);
            }

            $data = $this->validatedOfficialHsnRate($request);
            $record->fill($this->officialRatePayload($data, $record))->save();

            return response()->json([
                'message' => 'Official HSN/SAC GST rate verified successfully.',
                'record' => $this->formatHsnRecord($record->fresh()),
            ]);
        }

        $data = $this->validated($request, $type, $id);
        $record->fill($this->payload($type, $data, $record))->save();

        return response()->json([
            'message' => 'Master record updated successfully.',
            'record' => $record->fresh(),
        ]);
    }

    public function destroy(string $type, int $id)
    {
        [$model] = $this->definition($type);
        $record = $this->findScoped($model, $type, $id);

        if ($type === 'hsn' && $this->isOfficialHsn($record)) {
            throw ValidationException::withMessages([
                'hsn_code' => 'Official Government HSN/SAC records cannot be deleted or inactivated here.',
            ]);
        }

        if (Schema::hasColumn($record->getTable(), 'status')) {
            $record->fill(['status' => 'inactive'])->save();
        } else {
            $record->delete();
        }

        return response()->json(['message' => 'Master record deleted successfully.']);
    }

    private function definition(string $type): array
    {
        return match ($type) {
            'branch' => [Branch::class, ['name', 'type', 'city', 'state']],
            'warehouse' => [Warehouse::class, ['name', 'code']],
            'category', 'subcategory' => [ProductCategory::class, ['name']],
            'brand' => [Brand::class, ['name']],
            'unit' => [Unit::class, ['code', 'name']],
            'hsn' => [HsnMaster::class, ['hsn_code', 'description', 'chapter_code', 'code_type', 'taxability']],
            default => abort(404),
        };
    }

    private function validated(Request $request, string $type, ?int $id = null): array
    {
        $businessId = AppController::businessId();

        $data = $request->validate(match ($type) {
            'branch' => [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:500'],
                'city' => ['nullable', 'string', 'max:100'],
                'state' => ['nullable', 'string', 'max:100'],
                'state_id' => ['nullable', 'integer', Rule::exists('states', 'id')],
                'city_id' => ['nullable', 'integer', Rule::exists('cities', 'id')],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ],
            'warehouse' => [
                'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
                'name' => ['required', 'string', 'max:255'],
                'code' => ['nullable', 'string', 'max:50'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ],
            'category' => [
                'name' => ['required', 'string', 'max:255'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ],
            'subcategory' => [
                'parent_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where(fn ($query) => $query->whereNull('business_id')->orWhere('business_id', $businessId))],
                'name' => ['required', 'string', 'max:255'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ],
            'brand' => [
                'name' => ['required', 'string', 'max:255'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ],
            'unit' => [
                'code' => ['required', 'string', 'max:12', Rule::unique('units', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:255'],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ],
            'hsn' => [
                'hsn_code' => ['required', 'string', 'max:12'],
                'description' => ['required', 'string', 'max:5000'],
                'code_type' => ['required', Rule::in(['HSN', 'SAC'])],
                'taxability' => ['required', Rule::in(['taxable', 'exempt', 'nil_rated', 'non_gst'])],
                'chapter_code' => ['nullable', 'string', 'max:8'],
                'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
                'cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'effective_from' => ['required', 'date'],
                'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
                'verification_status' => ['nullable', Rule::in(['verified', 'classification_verified', 'rate_verified', 'rate_suggested', 'unverified'])],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ],
        });

        if ($type === 'hsn') {
            $this->validateHsn($data, $businessId, $id);
        }

        if ($type === 'branch') {
            $this->validateBranchLocation($data);
        }

        return $data;
    }

    private function payload(string $type, array $data, object $record): array
    {
        $businessId = AppController::businessId();
        $payload = $data;

        if (Schema::hasColumn($record->getTable(), 'business_id')) {
            $payload['business_id'] = $businessId;
        }

        if ($type === 'branch' && Schema::hasColumn('branches', 'tenant_id')) {
            $payload['tenant_id'] = $businessId;
        }

        if ($type === 'branch') {
            $payload = $this->branchPayload($payload);
        }

        if ($type === 'category') {
            $payload['parent_id'] = null;
        }

        if ($type === 'hsn') {
            $payload['cess_rate'] = $payload['cess_rate'] ?? 0;
            $payload['code_type'] = strtoupper($payload['code_type'] ?? 'HSN');
            $payload['verification_status'] = $payload['verification_status'] ?? 'verified';

            if (in_array($payload['taxability'] ?? 'taxable', ['exempt', 'nil_rated', 'non_gst'], true)) {
                $payload['gst_rate'] = 0;
            }

            if (($payload['verification_status'] ?? 'verified') === 'unverified') {
                $payload['status'] = 'inactive';
            }
        }

        return collect($payload)
            ->only(Schema::getColumnListing($record->getTable()))
            ->all();
    }

    private function branchPayload(array $payload): array
    {
        if (!empty($payload['state_id']) && Schema::hasTable('states')) {
            $payload['state'] = DB::table('states')->where('id', $payload['state_id'])->value('name') ?: ($payload['state'] ?? null);
        }

        if (!empty($payload['city_id']) && Schema::hasTable('cities')) {
            $payload['city'] = DB::table('cities')->where('id', $payload['city_id'])->value('name') ?: ($payload['city'] ?? null);
        }

        return $payload;
    }

    private function validateBranchLocation(array $data): void
    {
        if (empty($data['state_id']) || empty($data['city_id']) || !Schema::hasTable('cities')) {
            return;
        }

        $cityBelongsToState = DB::table('cities')
            ->where('id', $data['city_id'])
            ->where('state_id', $data['state_id'])
            ->exists();

        if (!$cityBelongsToState) {
            throw ValidationException::withMessages([
                'city_id' => 'Selected city does not belong to the selected state.',
            ]);
        }
    }

    private function validatedOfficialHsnRate(Request $request): array
    {
        return $request->validate([
            'taxability' => ['required', Rule::in(['taxable', 'exempt', 'nil_rated', 'non_gst'])],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notification_number' => ['nullable', 'string', 'max:255'],
            'source_reference' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function officialRatePayload(array $data, HsnMaster $record): array
    {
        $payload = [
            'taxability' => $data['taxability'],
            'gst_rate' => in_array($data['taxability'], ['exempt', 'nil_rated', 'non_gst'], true) ? 0 : $data['gst_rate'],
            'cess_rate' => $data['cess_rate'] ?? 0,
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'notification_number' => $data['notification_number'] ?? $record->notification_number,
            'source_reference' => $data['source_reference'] ?? $record->source_reference,
            'notes' => $data['notes'] ?? $record->notes,
            'verification_status' => 'verified',
            'status' => 'active',
            'updated_by' => auth()->id(),
        ];

        return collect($payload)
            ->only(Schema::getColumnListing($record->getTable()))
            ->all();
    }

    private function validateHsn(array $data, int $businessId, ?int $id = null): void
    {
        $duplicate = HsnMaster::query()
            ->where('business_id', $businessId)
            ->where('code_type', $data['code_type'])
            ->where('hsn_code', $data['hsn_code'])
            ->when($id, fn (Builder $query) => $query->whereKeyNot($id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'hsn_code' => 'This HSN/SAC code already exists for the selected code type.',
            ]);
        }

        if (($data['status'] ?? 'active') !== 'active') {
            return;
        }

        $from = $data['effective_from'];
        $to = $data['effective_to'] ?? null;

        $overlap = HsnMaster::query()
            ->where('business_id', $businessId)
            ->where('code_type', $data['code_type'])
            ->where('hsn_code', $data['hsn_code'])
            ->where('status', 'active')
            ->when($id, fn (Builder $query) => $query->whereKeyNot($id))
            ->whereDate('effective_from', '<=', $to ?: '9999-12-31')
            ->where(function (Builder $query) use ($from) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from);
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_from' => 'An active tax period already exists for this HSN/SAC code and date range.',
            ]);
        }
    }

    private function formatHsnRecord(HsnMaster $record): array
    {
        $data = $record->toArray();
        $data['is_official'] = $this->isOfficialHsn($record);
        $data['source_label'] = $data['is_official'] ? 'Official' : 'Business';
        $data['verification_label'] = $this->verificationLabel((string) ($record->verification_status ?? ''));
        $data['classification_verified'] = in_array($data['verification_label'], ['Classification Verified', 'Rate Suggested', 'Rate Verified'], true);
        $data['rate_suggested'] = $data['verification_label'] === 'Rate Suggested';
        $data['rate_verified'] = $data['verification_label'] === 'Rate Verified';
        $data['rate_warning'] = $data['rate_verified'] ? null : 'GST rate must be verified from latest CBIC notifications before billing.';

        return $data;
    }

    private function formatBranchRecord(Branch $record): array
    {
        $data = $record->toArray();

        if (!empty($data['state_id']) && Schema::hasTable('states')) {
            $data['state_name'] = DB::table('states')->where('id', $data['state_id'])->value('name');
        }

        if (!empty($data['city_id']) && Schema::hasTable('cities')) {
            $data['city_name'] = DB::table('cities')->where('id', $data['city_id'])->value('name');
        }

        $data['state'] = $data['state_name'] ?? $data['state'] ?? null;
        $data['city'] = $data['city_name'] ?? $data['city'] ?? null;

        return $data;
    }

    private function hsnSummary(Builder $query, int $businessId): array
    {
        $rows = (clone $query)
            ->selectRaw('
                COUNT(*) as total_records,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_records,
                SUM(CASE WHEN business_id IS NULL THEN 1 ELSE 0 END) as official_records,
                SUM(CASE WHEN business_id = ? THEN 1 ELSE 0 END) as custom_records,
                SUM(CASE WHEN code_type = "HSN" THEN 1 ELSE 0 END) as hsn_records,
                SUM(CASE WHEN code_type = "SAC" THEN 1 ELSE 0 END) as sac_records,
                SUM(CASE WHEN verification_status IN ("verified", "classification_verified", "Classification Verified", "rate_suggested", "Rate Suggested", "rate_verified", "Rate Verified") THEN 1 ELSE 0 END) as classification_verified_records,
                SUM(CASE WHEN verification_status IN ("rate_suggested", "Rate Suggested") THEN 1 ELSE 0 END) as rate_suggested_records,
                SUM(CASE WHEN verification_status IN ("verified", "rate_verified", "Rate Verified") THEN 1 ELSE 0 END) as rate_verified_records
            ', [$businessId])
            ->first();

        return [
            'total' => (int) ($rows->total_records ?? 0),
            'active' => (int) ($rows->active_records ?? 0),
            'official' => (int) ($rows->official_records ?? 0),
            'custom' => (int) ($rows->custom_records ?? 0),
            'hsn' => (int) ($rows->hsn_records ?? 0),
            'sac' => (int) ($rows->sac_records ?? 0),
            'classification_verified' => (int) ($rows->classification_verified_records ?? 0),
            'rate_suggested' => (int) ($rows->rate_suggested_records ?? 0),
            'rate_verified' => (int) ($rows->rate_verified_records ?? 0),
        ];
    }

    private function verificationLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'verified', 'rate_verified', 'rate verified' => 'Rate Verified',
            'rate_suggested', 'rate suggested' => 'Rate Suggested',
            'classification_verified', 'classification verified' => 'Classification Verified',
            default => 'Unverified',
        };
    }

    private function isOfficialHsn(object $record): bool
    {
        return $record instanceof HsnMaster && $record->getAttribute('business_id') === null;
    }

    private function findScoped(string $model, string $type, int $id): object
    {
        $query = $model::query()->where('id', $id);
        $this->scopeBusiness($query, $model, AppController::businessId());
        $this->scopeType($query, $type);

        return $query->firstOrFail();
    }

    private function scopeBusiness(Builder $query, string $model, int $businessId): void
    {
        $table = (new $model())->getTable();

        if (Schema::hasColumn($table, 'business_id')) {
            $businessIds = $this->businessScopeIds($table, $businessId);

            $query->where(function (Builder $inner) use ($businessIds, $table) {
                $inner->whereNull($table . '.business_id')->orWhereIn($table . '.business_id', $businessIds);
            });
        }
    }

    private function businessScopeIds(string $table, int $businessId): array
    {
        $ids = [$businessId];

        $knownIds = DB::table($table)
            ->whereNotNull('business_id')
            ->distinct()
            ->pluck('business_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($knownIds->count() === 1) {
            $ids[] = $knownIds->first();
        }

        return array_values(array_unique($ids));
    }

    private function scopeType(Builder $query, string $type): void
    {
        if ($type === 'category') {
            $query->whereNull('parent_id');
        }

        if ($type === 'subcategory') {
            $query->whereNotNull('parent_id');
        }
    }
}
