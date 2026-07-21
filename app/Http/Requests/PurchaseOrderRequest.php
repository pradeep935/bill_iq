<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'], 'warehouse_id' => ['required', 'integer'], 'purchase_requisition_id' => ['nullable', 'integer'],
            'supplier_id' => ['required', 'integer'], 'po_date' => ['required', 'date'], 'expected_delivery_date' => ['nullable', 'date'],
            'status' => ['required', 'in:draft,approved,sent,confirmed,cancelled'], 'terms_conditions' => ['nullable', 'string'], 'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'], 'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'], 'items.*.ordered_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.purchase_rate' => ['required', 'numeric', 'min:0'], 'items.*.gst_rate' => ['nullable', 'numeric', 'min:0'], 'items.*.remarks' => ['nullable', 'string'],
        ];
    }
}
