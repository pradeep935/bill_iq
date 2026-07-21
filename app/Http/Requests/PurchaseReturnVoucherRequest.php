<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseReturnVoucherRequest extends FormRequest
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
            'purchase_voucher_id' => ['nullable', 'integer'],
            'return_date' => ['required', 'date'],
            'supplier_debit_note_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'return_type' => ['required', Rule::in(['against_purchase', 'direct_return'])],
            'tax_type' => ['required', Rule::in(['intrastate', 'interstate', 'exempt'])],
            'settlement_type' => ['required', Rule::in(['supplier_credit', 'cash_refund', 'bank_refund', 'adjustment', 'pending'])],
            'settlement_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['draft', 'confirmed', 'approved'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.purchase_rate' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('return_type') === 'against_purchase' && !$this->filled('purchase_voucher_id')) {
                $validator->errors()->add('purchase_voucher_id', 'Original purchase voucher is required.');
            }

            if ($this->input('return_type') === 'direct_return') {
                if (!$this->filled('branch_id')) {
                    $validator->errors()->add('branch_id', 'Branch is required for direct purchase return.');
                }

                if (!$this->filled('warehouse_id')) {
                    $validator->errors()->add('warehouse_id', 'Warehouse is required for direct purchase return.');
                }
            }

            foreach ((array) $this->input('items', []) as $index => $item) {
                if ($this->input('return_type') === 'against_purchase' && empty($item['purchase_item_id'])) {
                    $validator->errors()->add("items.$index.purchase_item_id", 'Original purchase item is required.');
                }
            }
        });
    }
}
