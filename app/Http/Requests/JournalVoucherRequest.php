<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JournalVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ($this->user()->isSuperAdmin() || $this->user()->isAdmin());
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'voucher_type' => ['required', Rule::in(['opening', 'contra', 'journal', 'adjustment'])],
            'voucher_date' => ['required', 'date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'narration' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'approved'])],
            'entries' => ['required', 'array', 'min:2'],
            'entries.*.account_id' => ['required', 'integer'],
            'entries.*.customer_id' => ['nullable', 'integer'],
            'entries.*.supplier_id' => ['nullable', 'integer'],
            'entries.*.debit_amount' => ['nullable', 'numeric', 'min:0'],
            'entries.*.credit_amount' => ['nullable', 'numeric', 'min:0'],
            'entries.*.narration' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
