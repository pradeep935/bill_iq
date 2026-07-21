<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContraVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && ($this->user()->isSuperAdmin() || $this->user()->isAdmin());
    }

    public function rules(): array
    {
        return [
            'voucher_date' => ['required', 'date'],
            'from_account_id' => ['required', 'integer', 'different:to_account_id'],
            'to_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
