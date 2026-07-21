<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OtherIncomeVoucherRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'income_date' => ['required', 'date'],
            'income_category_id' => ['required', 'integer'],
            'income_account_id' => ['required', 'integer'],
            'received_into_account_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'payment_mode' => ['required', 'in:cash,bank_transfer,UPI,card,cheque,wallet'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'tax_type' => ['required', 'in:exclusive,inclusive,non_taxable'],
            'taxable_amount' => ['nullable', 'numeric', 'min:0'],
            'non_taxable_amount' => ['nullable', 'numeric', 'min:0'],
            'cgst_amount' => ['nullable', 'numeric', 'min:0'],
            'sgst_amount' => ['nullable', 'numeric', 'min:0'],
            'igst_amount' => ['nullable', 'numeric', 'min:0'],
            'cess_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'status' => ['required', 'in:draft,submitted,approved,posted'],
            'narration' => ['nullable', 'string'],
        ];
    }
}
