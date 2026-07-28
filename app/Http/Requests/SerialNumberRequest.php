<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SerialNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'serial_number' => ['required', 'string', 'max:120'],
            'secondary_serial_number' => ['nullable', 'string', 'max:120'],
            'imei_1' => ['nullable', 'string', 'max:80'],
            'imei_2' => ['nullable', 'string', 'max:80'],
            'purchase_reference' => ['nullable', 'string', 'max:255'],
            'sale_reference' => ['nullable', 'string', 'max:255'],
            'current_status' => ['nullable', Rule::in(['in_stock', 'reserved', 'sold', 'returned', 'damaged', 'under_repair', 'lost', 'transferred', 'blocked'])],
            'condition' => ['nullable', Rule::in(['new', 'good', 'fair', 'damaged', 'defective', 'refurbished'])],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expiry_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
