<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuotationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'], 'quotation_date' => ['required', 'date'], 'valid_until' => ['nullable', 'date'],
            'customer_id' => ['required', 'integer'], 'discount_type' => ['nullable', 'in:percentage,amount'], 'discount_value' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string'], 'internal_notes' => ['nullable', 'string'],
            'terms_conditions' => ['nullable', 'string'], 'status' => ['required', 'in:draft,sent,accepted,rejected,cancelled'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'], 'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'], 'items.*.description' => ['nullable', 'string'], 'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_id' => ['nullable', 'integer'], 'items.*.unit_price' => ['required', 'numeric', 'min:0'], 'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0'], 'items.*.cess_rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
