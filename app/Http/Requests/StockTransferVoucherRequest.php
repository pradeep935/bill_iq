<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockTransferVoucherRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'transfer_date' => ['required', 'date'],
            'source_branch_id' => ['nullable', 'integer'],
            'source_warehouse_id' => ['required', 'integer'],
            'destination_branch_id' => ['nullable', 'integer'],
            'destination_warehouse_id' => ['required', 'integer'],
            'transfer_type' => ['required', 'in:immediate,dispatch_receive,inter_branch,inter_warehouse'],
            'expected_delivery_date' => ['nullable', 'date'],
            'status' => ['required', 'in:draft,submitted,approved,dispatched,received'],
            'dispatch_reference' => ['nullable', 'string', 'max:255'],
            'vehicle_number' => ['nullable', 'string', 'max:80'],
            'courier_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.source_batch_id' => ['nullable', 'integer'],
            'items.*.destination_batch_id' => ['nullable', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.requested_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.approved_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.source_location' => ['nullable', 'string', 'max:255'],
            'items.*.destination_location' => ['nullable', 'string', 'max:255'],
            'items.*.remarks' => ['nullable', 'string'],
        ];
    }
}
