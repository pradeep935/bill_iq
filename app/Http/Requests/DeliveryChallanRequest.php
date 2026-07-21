<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeliveryChallanRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'], 'warehouse_id' => ['required', 'integer'], 'challan_date' => ['required', 'date'],
            'customer_id' => ['required', 'integer'], 'sales_order_id' => ['nullable', 'integer'], 'vehicle_number' => ['nullable', 'string'],
            'transporter_name' => ['nullable', 'string'], 'dispatch_person_id' => ['nullable', 'integer'], 'dispatch_reference' => ['nullable', 'string'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'], 'tracking_number' => ['nullable', 'string'], 'status' => ['required', 'in:draft,dispatched,delivered'],
            'remarks' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.sales_order_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required', 'integer'], 'items.*.product_variant_id' => ['nullable', 'integer'], 'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.serial_id' => ['nullable', 'integer'], 'items.*.ordered_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.dispatch_quantity' => ['required', 'numeric', 'min:0.001'], 'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.warehouse_location' => ['nullable', 'string'],
        ];
    }
}
