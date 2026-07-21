<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'], 'warehouse_id' => ['required', 'integer'], 'receipt_date' => ['required', 'date'],
            'purchase_order_id' => ['nullable', 'integer'], 'supplier_id' => ['required', 'integer'], 'supplier_challan_number' => ['nullable', 'string'],
            'qc_status' => ['required', 'in:pending,passed,failed,partial'], 'status' => ['required', 'in:draft,received,cancelled'], 'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'], 'items.*.purchase_order_item_id' => ['nullable', 'integer'], 'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'], 'items.*.batch_id' => ['nullable', 'integer'], 'items.*.serial_id' => ['nullable', 'integer'],
            'items.*.ordered_quantity' => ['nullable', 'numeric', 'min:0'], 'items.*.received_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.rejected_quantity' => ['nullable', 'numeric', 'min:0'], 'items.*.damaged_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'], 'items.*.qc_status' => ['nullable', 'in:pending,passed,failed'], 'items.*.warehouse_location' => ['nullable', 'string'],
        ];
    }
}
