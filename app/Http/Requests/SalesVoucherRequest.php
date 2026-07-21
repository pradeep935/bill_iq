<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && in_array((int) $this->user()->role_id, [1, 2, 3], true);
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'sale_type' => ['required', Rule::in(['cash', 'credit'])],
            'invoice_type' => ['required', Rule::in(['tax_invoice', 'bill_of_supply', 'retail_invoice'])],
            'tax_type' => ['required', Rule::in(['intrastate', 'interstate', 'exempt', 'nil_rated'])],
            'place_of_supply_state_id' => ['nullable', 'integer'],
            'voucher_discount_type' => ['nullable', Rule::in(['percentage', 'amount'])],
            'voucher_discount_value' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'salesperson_id' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:3000'],
            'status' => ['required', Rule::in(['draft', 'hold', 'confirmed', 'approved'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.selling_rate' => ['required', 'numeric', 'min:0'],
            'items.*.mrp' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', Rule::in(['percentage', 'amount'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method_id' => ['required_with:payments', 'integer'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0.01'],
            'payments.*.reference_number' => ['nullable', 'string', 'max:255'],
            'payments.*.payment_date' => ['nullable', 'date'],
            'payments.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('sale_type') === 'credit' && !$this->filled('customer_id')) {
                $validator->errors()->add('customer_id', 'Customer is required for credit sale.');
            }
        });
    }
}
