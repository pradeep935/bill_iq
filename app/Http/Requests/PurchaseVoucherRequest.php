<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseVoucherRequest extends FormRequest
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
            'supplier_id' => ['required', 'integer'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['required', 'date'],
            'supplier_invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'purchase_type' => ['required', Rule::in(['cash', 'credit'])],
            'tax_type' => ['required', Rule::in(['intrastate', 'interstate', 'exempt'])],
            'discount_type' => ['nullable', Rule::in(['percentage', 'amount'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['draft', 'confirmed', 'approved'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.purchase_rate' => ['required', 'numeric', 'min:0'],
            'items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.mrp' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['percentage', 'amount'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.batch_number' => ['nullable', 'string', 'max:100'],
            'items.*.manufacturing_date' => ['nullable', 'date'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'items.*.warehouse_location' => ['nullable', 'string', 'max:255'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (
                    isset($item['selling_price'], $item['mrp']) &&
                    $item['selling_price'] !== null &&
                    $item['mrp'] !== null &&
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
