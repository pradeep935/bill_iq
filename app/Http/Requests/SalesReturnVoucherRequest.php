<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesReturnVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->isAdmin());
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'sales_voucher_id' => ['nullable', 'integer'],
            'return_date' => ['required', 'date'],
            'return_type' => ['required', Rule::in(['against_sale', 'direct_return'])],
            'tax_type' => ['required', Rule::in(['intrastate', 'interstate', 'exempt', 'nil_rated'])],
            'place_of_supply_state_id' => ['nullable', 'integer'],
            'settlement_type' => ['required', Rule::in(['customer_credit', 'cash_refund', 'bank_refund', 'upi_refund', 'card_refund', 'invoice_adjustment', 'pending'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'confirmed', 'approved'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sales_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.selling_rate' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.return_reason' => ['nullable', 'string', 'max:1000'],
            'items.*.condition_status' => ['nullable', Rule::in(['good', 'damaged', 'opened', 'expired', 'defective'])],
            'items.*.restock_status' => ['required', Rule::in(['restock', 'damaged_stock', 'expired_stock', 'non_restockable'])],
            'refunds' => ['nullable', 'array'],
            'refunds.*.payment_method_id' => ['required_with:refunds', 'integer'],
            'refunds.*.amount' => ['required_with:refunds', 'numeric', 'min:0.01'],
            'refunds.*.refund_date' => ['nullable', 'date'],
            'refunds.*.reference_number' => ['nullable', 'string', 'max:255'],
            'refunds.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('return_type') === 'against_sale' && !$this->filled('sales_voucher_id')) {
                $validator->errors()->add('sales_voucher_id', 'Original sales invoice is required.');
            }

            if ($this->input('return_type') === 'direct_return' && !$this->filled('reason')) {
                $validator->errors()->add('reason', 'Reason is required for direct sales return.');
            }

            foreach ((array) $this->input('items', []) as $index => $item) {
                if ($this->input('return_type') === 'against_sale' && empty($item['sales_item_id'])) {
                    $validator->errors()->add("items.$index.sales_item_id", 'Original sales item is required.');
                }
            }
        });
    }
}
