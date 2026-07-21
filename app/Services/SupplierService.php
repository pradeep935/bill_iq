<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplierService
{
    public function list(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return Supplier::query()
            ->where('business_id', $businessId)
            ->when(!empty($filters['search']), function (Builder $query) use ($filters) {
                $search = trim((string) $filters['search']);
                $query->where(function (Builder $inner) use ($search) {
                    $inner
                        ->where('supplier_name', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%')
                        ->orWhere('supplier_code', 'like', '%' . $search . '%')
                        ->orWhere('gstin', 'like', '%' . $search . '%')
                        ->orWhere('mobile', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            })
            ->when(($filters['status'] ?? '') === 'deleted', fn (Builder $query) => $query->onlyTrashed())
            ->when(!empty($filters['status']) && $filters['status'] !== 'deleted', fn (Builder $query) => $query->where('status', $filters['status']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $data['business_id'] = $businessId;
            $data['created_by'] = Auth::id();
            $data['name'] = $data['supplier_name'];
            $data['phone'] = $data['phone'] ?? $data['mobile'];
            $data['supplier_code'] = $data['supplier_code'] ?: $this->nextSupplierCode($businessId);

            return Supplier::query()->create($data)->fresh();
        });
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $this->assertBusiness($supplier);
            $data['name'] = $data['supplier_name'];
            $data['phone'] = $data['phone'] ?? $data['mobile'];
            $supplier->update($data);

            return $supplier->fresh();
        });
    }

    public function delete(Supplier $supplier): void
    {
        $this->assertBusiness($supplier);
        $supplier->delete();
    }

    public function restore(int $id): Supplier
    {
        $supplier = Supplier::withTrashed()
            ->where('business_id', AppController::businessId())
            ->where('id', $id)
            ->firstOrFail();
        $supplier->restore();

        return $supplier->fresh();
    }

    public function present(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'supplier_code' => $supplier->supplier_code,
            'supplier_name' => $supplier->supplier_name ?: $supplier->name,
            'contact_person' => $supplier->contact_person,
            'mobile' => $supplier->mobile ?: $supplier->phone,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'gstin' => $supplier->gstin,
            'pan' => $supplier->pan,
            'billing_address' => $supplier->billing_address,
            'shipping_address' => $supplier->shipping_address,
            'state_id' => $supplier->state_id,
            'city' => $supplier->city,
            'pincode' => $supplier->pincode,
            'opening_balance' => (float) $supplier->opening_balance,
            'opening_balance_type' => $supplier->opening_balance_type ?: 'credit',
            'credit_limit' => $supplier->credit_limit !== null ? (float) $supplier->credit_limit : null,
            'credit_days' => $supplier->credit_days,
            'status' => $supplier->status ?: 'active',
            'deleted_at' => optional($supplier->deleted_at)->toDateTimeString(),
        ];
    }

    private function assertBusiness(Supplier $supplier): void
    {
        abort_unless((int) $supplier->business_id === AppController::businessId(), 404);
    }

    private function nextSupplierCode(int $businessId): string
    {
        $prefix = 'SUP-';
        $last = Supplier::withTrashed()
            ->where('business_id', $businessId)
            ->where('supplier_code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('supplier_code');

        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
