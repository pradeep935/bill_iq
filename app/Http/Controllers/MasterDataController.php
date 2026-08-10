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

        $query = $model::query();
        $this->scopeBusiness($query, $model, $businessId);
        $this->scopeType($query, $type);

        if ($type === 'subcategory' && $request->filled('parent_id')) {
            $query->where('parent_id', $request->query('parent_id'));
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function (Builder $inner) use ($search, $searchColumns) {
                foreach ($searchColumns as $column) {
                    $inner->orWhere($column, 'like', '%' . $search . '%');
                }
            });
        }

        return response()->json([
            'data' => $query->latest('id')->paginate((int) $request->query('per_page', 15)),
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
                'verification_status' => ['nullable', Rule::in(['verified', 'unverified'])],
                'status' => ['required', Rule::in(['active', 'inactive'])],
            ],
        });

        if ($type === 'hsn') {
            $this->validateHsn($data, $businessId, $id);
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

            if (($payload['verification_status'] ?? 'verified') !== 'verified') {
                $payload['status'] = 'inactive';
            }
        }

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
