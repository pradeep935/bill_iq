<?php

namespace App\Http\Controllers;

use App\Models\HsnMaster;
use App\Models\Product;
use App\Services\MasterDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BusinessHsnSacController extends Controller
{
    public function index()
    {
        if ($redirect = AppController::guardPage('products')) {
            return $redirect;
        }

        return Inertia::render('Product/HsnMaster', [
            'page' => 'business-hsn-master',
            'title' => 'HSN/SAC Master',
            'gst_rate_slabs' => app(MasterDataService::class)->gstRateSlabs(),
        ]);
    }

    public function list(Request $request)
    {
        $businessId = AppController::businessId();
        $search = trim((string) $request->query('search'));

        $query = HsnMaster::query()
            ->where('business_id', $businessId)
            ->when($request->filled('code_type'), fn (Builder $q) => $q->where('code_type', strtoupper((string) $request->query('code_type'))))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('reference_status'), function (Builder $q) use ($request) {
                match ($request->query('reference_status')) {
                    'manual' => $q->whereNull('reference_hsn_master_id'),
                    'matched' => $q->whereNotNull('reference_hsn_master_id')->where('verification_status', 'classification_verified'),
                    'modified' => $q->whereNotNull('reference_hsn_master_id')->where('verification_status', 'rate_suggested'),
                    default => null,
                };
            })
            ->when($search !== '', function (Builder $q) use ($search) {
                $q->where(fn (Builder $inner) => $inner
                    ->where('hsn_code', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%'));
            })
            ->orderBy('code_type')
            ->orderBy('hsn_code');

        return response()->json([
            'data' => $query->paginate(max(10, min((int) $request->query('per_page', 15), 100)))
                ->through(fn (HsnMaster $record) => $this->present($record)),
        ]);
    }

    public function referenceSearch(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $codeType = strtoupper((string) $request->query('code_type', 'HSN'));
        $codeType = in_array($codeType, ['HSN', 'SAC'], true) ? $codeType : 'HSN';

        if ($search === '') {
            return response()->json(['data' => []]);
        }

        $normalizedCode = preg_replace('/\D+/', '', $search);
        $tokens = collect(preg_split('/\s+/', strtolower($search), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => strlen($token) >= 3 && !in_array($token, ['hsn', 'sac', 'gst'], true))
            ->values();

        $records = HsnMaster::query()
            ->whereNull('business_id')
            ->where('code_type', $codeType)
            ->where(function (Builder $q) use ($search, $normalizedCode, $tokens) {
                if ($normalizedCode !== '') {
                    $q->where('hsn_code', $normalizedCode)
                        ->orWhere('hsn_code', 'like', $normalizedCode . '%');
                }

                $q->orWhere('description', 'like', '%' . $search . '%');

                foreach ($tokens as $token) {
                    $q->orWhere('description', 'like', '%' . $token . '%');
                    if (Schema::hasColumn('hsn_masters', 'search_keywords')) {
                        $q->orWhere('search_keywords', 'like', '%' . $token . '%');
                    }
                }
            })
            ->where(fn (Builder $q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->orderByRaw('CASE WHEN hsn_code = ? THEN 0 WHEN hsn_code LIKE ? THEN 1 ELSE 2 END', [$normalizedCode ?: $search, ($normalizedCode ?: $search) . '%'])
            ->orderBy('hsn_code')
            ->limit(10)
            ->get()
            ->map(fn (HsnMaster $record) => $this->present($record));

        return response()->json(['data' => $records]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['business_id'] = AppController::businessId();
        $data['verification_status'] = $this->referenceStatus($data);

        $record = HsnMaster::query()->create($this->tablePayload($data));

        return response()->json([
            'message' => 'HSN/SAC saved in your business master.',
            'record' => $this->present($record->fresh()),
        ], 201);
    }

    public function update(Request $request, HsnMaster $hsn)
    {
        $this->assertOwnRecord($hsn);

        $data = $this->validated($request, $hsn->id);
        $data['business_id'] = AppController::businessId();
        $data['verification_status'] = $this->referenceStatus($data);

        $hsn->fill($this->tablePayload($data))->save();

        return response()->json([
            'message' => 'HSN/SAC updated successfully.',
            'record' => $this->present($hsn->fresh()),
        ]);
    }

    public function destroy(HsnMaster $hsn)
    {
        $this->assertOwnRecord($hsn);

        $usedByProduct = Product::query()
            ->where('business_id', AppController::businessId())
            ->where(function (Builder $query) use ($hsn) {
                $query->where('hsn_master_id', $hsn->id)->orWhere('hsn_id', $hsn->id);
            })
            ->exists();

        $usedByProduct ? $hsn->fill(['status' => 'inactive'])->save() : $hsn->delete();

        return response()->json(['message' => 'HSN/SAC deactivated successfully.']);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $businessId = AppController::businessId();

        $data = $request->validate([
            'code_type' => ['required', Rule::in(['HSN', 'SAC'])],
            'hsn_code' => ['required', 'string', 'max:12', 'regex:/^[0-9]+$/'],
            'description' => ['required', 'string', 'max:5000'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'taxability' => ['required', Rule::in(['taxable', 'nil_rated', 'exempt', 'non_gst'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reference_hsn_master_id' => ['nullable', Rule::exists('hsn_masters', 'id')->whereNull('business_id')],
        ]);

        $duplicate = HsnMaster::query()
            ->where('business_id', $businessId)
            ->where('code_type', $data['code_type'])
            ->where('hsn_code', $data['hsn_code'])
            ->when($id, fn (Builder $q) => $q->whereKeyNot($id))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'hsn_code' => 'This HSN/SAC already exists in your master.',
            ]);
        }

        if (in_array($data['taxability'], ['nil_rated', 'exempt', 'non_gst'], true)) {
            $data['gst_rate'] = 0;
        }

        $data['cess_rate'] = $data['cess_rate'] ?? 0;
        $data['effective_from'] = now()->toDateString();

        return $data;
    }

    private function assertOwnRecord(HsnMaster $hsn): void
    {
        abort_unless((int) $hsn->business_id === AppController::businessId(), 403);
    }

    private function referenceStatus(array $data): string
    {
        $referenceId = $data['reference_hsn_master_id'] ?? null;
        if (!$referenceId) {
            return 'unverified';
        }

        $reference = HsnMaster::query()->whereNull('business_id')->find($referenceId);
        if (!$reference) {
            return 'unverified';
        }

        $same = (string) $reference->description === (string) $data['description']
            && (float) $reference->gst_rate === (float) $data['gst_rate']
            && (float) ($reference->cess_rate ?? 0) === (float) ($data['cess_rate'] ?? 0)
            && (string) $reference->taxability === (string) $data['taxability'];

        return $same ? 'classification_verified' : 'rate_suggested';
    }

    private function present(HsnMaster $record): array
    {
        $reference = $record->reference_hsn_master_id
            ? HsnMaster::query()->whereNull('business_id')->find($record->reference_hsn_master_id)
            : null;

        $status = 'Manual / Not Matched';
        if ($reference) {
            $status = ((float) $reference->gst_rate === (float) $record->gst_rate
                && (string) $reference->description === (string) $record->description
                && (string) $reference->taxability === (string) $record->taxability)
                ? 'Matched with BillIQ'
                : 'Modified from BillIQ';
        }

        return [
            'id' => $record->id,
            'business_id' => $record->business_id,
            'reference_hsn_master_id' => $record->reference_hsn_master_id ?? null,
            'code_type' => $record->code_type,
            'hsn_code' => $record->hsn_code,
            'description' => $record->description,
            'gst_rate' => $record->gst_rate !== null ? (float) $record->gst_rate : 0,
            'cess_rate' => $record->cess_rate !== null ? (float) $record->cess_rate : 0,
            'taxability' => $record->taxability ?: 'taxable',
            'status' => $record->status ?: 'active',
            'reference_status' => $status,
            'reference_gst_rate' => $reference?->gst_rate !== null ? (float) $reference->gst_rate : null,
        ];
    }

    private function tablePayload(array $data): array
    {
        return collect($data)
            ->only(Schema::getColumnListing('hsn_masters'))
            ->all();
    }
}
