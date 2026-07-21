<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ($this->user()->isSuperAdmin() || $this->user()->isAdmin());
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'payment_date' => ['required', 'date'],
            'payment_mode' => ['required', Rule::in(['cash', 'bank_transfer', 'upi', 'card', 'cheque', 'wallet', 'mixed'])],
            'cash_bank_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'discount_received' => ['nullable', 'numeric', 'min:0'],
            'write_off_amount' => ['nullable', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'instrument_number' => ['nullable', 'string', 'max:255'],
            'instrument_date' => ['nullable', 'date'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'approved'])],
            'allocations' => ['nullable', 'array'],
            'allocations.*.reference_type' => ['required_with:allocations', 'string'],
            'allocations.*.reference_id' => ['required_with:allocations', 'integer'],
            'allocations.*.original_amount' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.allocated_amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
        ];
    }
}
