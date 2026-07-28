<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BarcodeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'barcode_id' => ['nullable', 'integer'],
            'product_id' => ['required', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'serial_number_id' => ['nullable', 'integer'],
            'barcode' => ['required', 'string', 'max:120'],
            'format' => ['nullable', Rule::in(['CODE128', 'EAN-13', 'EAN-8', 'UPC-A', 'QR'])],
            'barcode_type' => ['nullable', Rule::in(['internal', 'manufacturer', 'supplier', 'variant', 'unit', 'batch', 'serial'])],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
