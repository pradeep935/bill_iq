<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function list(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return Customer::query()
            ->where('business_id', $businessId)
            ->when(($filters['status'] ?? '') === 'deleted', fn (Builder $q) => $q->onlyTrashed())
            ->when(!empty($filters['status']) && ($filters['status'] ?? '') !== 'deleted', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['type']), fn (Builder $q) => $q->where('customer_type', $filters['type']))
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $search = '%' . $filters['search'] . '%';
                $q->where(function (Builder $query) use ($search) {
                    $query->where('customer_name', 'like', $search)
                        ->orWhere('customer_code', 'like', $search)
                        ->orWhere('mobile', 'like', $search)
                        ->orWhere('phone', 'like', $search)
                        ->orWhere('email', 'like', $search)
                        ->orWhere('gstin', 'like', $search);
                });
            })
            ->orderBy('customer_name')
            ->paginate($perPage);
    }

    public function create(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $customer = Customer::query()->create(array_merge($this->attributes($data), [
                'business_id' => $businessId,
                'customer_code' => ($data['customer_code'] ?? '') ?: $this->nextCode($businessId),
                'created_by' => Auth::id(),
            ]));

            AuditLogger::record([
                'module_name' => 'Customer',
                'record_id' => $customer->id,
                'action_type' => 'Create',
                'business_id' => $businessId,
                'summary' => 'Customer created',
            ]);

            return $customer;
        });
    }

    public function update(Customer $customer, array $data): Customer
    {
        $this->assertBusiness($customer);
        $customer->update(array_merge($this->attributes($data), ['updated_by' => Auth::id()]));

        return $customer->fresh();
    }

    public function delete(Customer $customer): void
    {
        $this->assertBusiness($customer);
        $customer->delete();
    }

    public function restore(int $id): Customer
    {
        $customer = Customer::withTrashed()->where('business_id', AppController::businessId())->where('id', $id)->firstOrFail();
        $customer->restore();

        return $customer;
    }

    public function defaultWalkIn(): Customer
    {
        $businessId = AppController::businessId();

        $customer = Customer::withTrashed()->firstOrCreate(
            ['business_id' => $businessId, 'customer_type' => 'walk_in', 'customer_code' => 'WALK-IN'],
            [
                'customer_name' => 'Walk-in Customer',
                'status' => 'active',
                'opening_balance' => 0,
                'created_by' => Auth::id(),
            ]
        );

        if ($customer->trashed()) {
            $customer->restore();
        }

        return $customer;
    }

    public function search(string $search)
    {
        return Customer::query()
            ->where('business_id', AppController::businessId())
            ->where('status', 'active')
            ->where(function (Builder $q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('customer_name', 'like', $like)
                    ->orWhere('customer_code', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('gstin', 'like', $like);
            })
            ->limit(20)
            ->get();
    }

    private function attributes(array $data): array
    {
        $attributes = [
            'customer_name' => $data['customer_name'],
            'customer_type' => $data['customer_type'],
            'contact_person' => $data['contact_person'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'gstin' => $data['gstin'] ?? null,
            'pan' => $data['pan'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
            'shipping_address' => $data['shipping_address'] ?? null,
            'state_id' => $data['state_id'] ?? null,
            'city' => $data['city'] ?? null,
            'pincode' => $data['pincode'] ?? null,
            'opening_balance' => $data['opening_balance'] ?? 0,
            'opening_balance_type' => $data['opening_balance_type'] ?? null,
            'credit_limit' => $data['credit_limit'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'price_type' => $data['price_type'] ?? null,
            'status' => $data['status'],
        ];

        if (!empty($data['customer_code'])) {
            $attributes['customer_code'] = $data['customer_code'];
        }

        return $attributes;
    }

    private function nextCode(int $businessId): string
    {
        $prefix = 'CUS-';
        $last = Customer::query()
            ->where('business_id', $businessId)
            ->where('customer_code', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('customer_code');
        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function assertBusiness(Customer $customer): void
    {
        abort_unless((int) $customer->business_id === AppController::businessId(), 404);
    }
}
