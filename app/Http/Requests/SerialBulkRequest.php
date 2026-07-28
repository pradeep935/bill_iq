<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SerialBulkRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string', 'max:40'],
            'purchase_reference' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'warranty_expiry_date' => ['nullable', 'date'],
            'serials' => ['required', 'array', 'min:1', 'max:500'],
            'serials.*.serial_number' => ['nullable', 'string', 'max:120'],
            'serials.*.imei_1' => ['nullable', 'string', 'max:80'],
            'serials.*.imei_2' => ['nullable', 'string', 'max:80'],
        ];
    }
}
