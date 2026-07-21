<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->isAdmin());
    }

    public function rules(): array
    {
        return [
            'supplier_code' => ['nullable', 'string', 'max:40'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'pan' => ['nullable', 'string', 'size:10', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'billing_address' => ['required', 'string', 'max:2000'],
            'shipping_address' => ['nullable', 'string', 'max:2000'],
            'state_id' => ['nullable', 'integer'],
            'city' => ['required', 'string', 'max:120'],
            'pincode' => ['required', 'string', 'max:12'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => ['required', Rule::in(['debit', 'credit'])],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $businessId = AppController::businessId();
            $supplierId = (int) ($this->route('supplier') ?: $this->input('id') ?: 0);
            $name = trim((string) $this->input('supplier_name'));
            $mobile = trim((string) $this->input('mobile'));
            $gstin = trim((string) $this->input('gstin'));
            $code = trim((string) $this->input('supplier_code'));

            if ($this->supplierExists($businessId, $supplierId, $name, $mobile, $gstin, $code)) {
                $validator->errors()->add('supplier_name', 'Supplier with same name, code, mobile or GSTIN already exists.');
            }
        });
    }

    private function supplierExists(int $businessId, int $supplierId, string $name, string $mobile, string $gstin, string $code): bool
    {
        return Supplier::withTrashed()
            ->where('business_id', $businessId)
            ->where('id', '!=', $supplierId)
            ->where(function ($query) use ($name, $mobile, $gstin, $code) {
                $query->where('supplier_name', $name)->orWhere('name', $name);

                if ($mobile !== '') {
                    $query->orWhere('mobile', $mobile)->orWhere('phone', $mobile);
                }

                if ($gstin !== '') {
                    $query->orWhere('gstin', $gstin);
                }

                if ($code !== '') {
                    $query->orWhere('supplier_code', $code);
                }
            })
            ->exists();
    }
}
