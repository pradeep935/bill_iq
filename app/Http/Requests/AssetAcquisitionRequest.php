<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetAcquisitionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'acquisition_date' => ['required', 'date'],
            'source_type' => ['required', Rule::in(['purchase_invoice', 'expense', 'opening', 'construction_in_progress', 'manual', 'transfer', 'donation', 'lease'])],
            'source_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'invoice_number' => ['nullable', 'string'],
            'invoice_date' => ['nullable', 'date'],
            'asset_category_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'additional_cost' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'input_tax_credit_eligible' => ['boolean'],
            'non_creditable_tax_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_status' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['draft', 'submitted', 'approved', 'posted', 'cancelled'])],
            'narration' => ['nullable', 'string'],
        ];
    }
}
