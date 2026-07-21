<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SalesOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'], 'warehouse_id' => ['required', 'integer'], 'quotation_id' => ['nullable', 'integer'],
            'customer_id' => ['required', 'integer'], 'order_date' => ['required', 'date'], 'expected_delivery_date' => ['nullable', 'date'],
            'sales_person_id' => ['nullable', 'integer'], 'order_status' => ['required', 'in:draft,pending_approval,approved,processing,cancelled'],
            'shipping' => ['nullable', 'numeric', 'min:0'], 'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'], 'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'], 'items.*.unit_id' => ['nullable', 'integer'], 'items.*.description' => ['nullable', 'string'],
            'items.*.ordered_quantity' => ['required', 'numeric', 'min:0.001'], 'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'], 'items.*.gst_rate' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
