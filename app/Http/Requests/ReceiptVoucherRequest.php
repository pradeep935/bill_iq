<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiptVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ($this->user()->isSuperAdmin() || $this->user()->isAdmin());
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'customer_id' => ['required', 'integer'],
            'receipt_date' => ['required', 'date'],
            'receipt_mode' => ['required', Rule::in(['cash', 'bank_transfer', 'upi', 'card', 'cheque', 'wallet', 'mixed'])],
            'cash_bank_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'discount_allowed' => ['nullable', 'numeric', 'min:0'],
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
