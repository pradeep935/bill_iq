<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpeningStockVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->isAdmin());
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'opening_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'confirmed', 'approved'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.batch_no' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.purchase_cost' => ['required', 'numeric', 'min:0'],
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.mrp' => ['nullable', 'numeric', 'min:0'],
            'items.*.warehouse_location' => ['nullable', 'string', 'max:255'],
            'items.*.manufacturing_date' => ['nullable', 'date'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (
                    isset($item['mrp'], $item['selling_price']) &&
                    $item['mrp'] !== null &&
                    $item['selling_price'] !== null &&
                    (float) $item['selling_price'] > (float) $item['mrp']
                ) {
                    $validator->errors()->add("items.$index.selling_price", 'Selling price cannot be greater than MRP.');
                }

                if (
                    !empty($item['manufacturing_date']) &&
                    !empty($item['expiry_date']) &&
                    strtotime($item['expiry_date']) < strtotime($item['manufacturing_date'])
                ) {
                    $validator->errors()->add("items.$index.expiry_date", 'Expiry date cannot be before manufacturing date.');
                }
            }
        });
    }
}
