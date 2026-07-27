<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'opening_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'posted'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.batch_no' => ['nullable', 'string', 'max:100'],
            'items.*.serial_number_id' => ['nullable', 'integer'],
            'items.*.serial_id' => ['nullable', 'integer'],
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

    public function messages(): array
    {
        return [
            'branch_id.required' => 'Please select a branch.',
            'items.*.quantity.required' => 'Quantity is required.',
            'items.*.quantity.gt' => 'Quantity must be greater than zero.',
            'items.*.purchase_cost.required' => 'Cost price is required.',
            'items.*.purchase_cost.min' => 'Cost price cannot be negative.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $seen = [];
            $integerUnits = ['PCS', 'BOX', 'UNIT', 'NOS', 'SET', 'PAIR'];

            foreach ((array) $this->input('items', []) as $index => $item) {
                $mrp = $item['mrp'] ?? null;
                $sellingPrice = $item['selling_price'] ?? null;
                $purchaseCost = $item['purchase_cost'] ?? null;

                if (
                    $this->input('status') === 'posted' &&
                    !$this->allowsFreeOpeningStock() &&
                    ($purchaseCost === null || $purchaseCost === '' || (float) $purchaseCost <= 0)
                ) {
                    $validator->errors()->add("items.$index.purchase_cost", 'Cost price must be greater than zero.');
                }

                if (
                    $mrp !== null &&
                    $mrp !== '' &&
                    (float) $mrp > 0 &&
                    $sellingPrice !== null &&
                    $sellingPrice !== '' &&
                    (float) $sellingPrice > (float) $mrp
                ) {
                    $validator->errors()->add("items.$index.selling_price", 'Selling price cannot be greater than MRP.');
                }

                if (
                    !empty($item['manufacturing_date']) &&
                    !empty($item['expiry_date']) &&
                    strtotime($item['expiry_date']) <= strtotime($item['manufacturing_date'])
                ) {
                    $validator->errors()->add("items.$index.expiry_date", 'Expiry date must be greater than manufacturing date.');
                }

                if (
                    $this->input('status') === 'posted' &&
                    !empty($item['expiry_date']) &&
                    strtotime($item['expiry_date']) < strtotime(date('Y-m-d'))
                ) {
                    $validator->errors()->add("items.$index.expiry_date", 'Expired opening stock cannot be posted.');
                }

                $key = implode('|', [
                    $this->input('branch_id') ?? '',
                    $this->input('warehouse_id') ?? '',
                    $item['product_id'] ?? '',
                    $item['product_variant_id'] ?? '',
                    $item['batch_id'] ?? '',
                    strtoupper(trim((string) ($item['batch_no'] ?? ''))),
                    strtoupper(trim((string) ($item['warehouse_location'] ?? ''))),
                    $item['serial_number_id'] ?? $item['serial_id'] ?? '',
                ]);

                if (isset($seen[$key])) {
                    $validator->errors()->add("items.$index.product_id", 'This product already exists in the voucher. Please update the existing quantity.');
                }

                $seen[$key] = true;

                $unit = strtoupper((string) ($item['unit'] ?? ''));
                $quantity = $item['quantity'] ?? null;
                if ($quantity !== null && in_array($unit, $integerUnits, true) && floor((float) $quantity) !== (float) $quantity) {
                    $validator->errors()->add("items.$index.quantity", 'Decimal quantity is not allowed for this unit.');
                }
            }
        });
    }

    private function allowsFreeOpeningStock(): bool
    {
        if (config('inventory.allow_free_opening_stock', false)) {
            return true;
        }

        if (
            Schema::hasTable('business_inventory_settings') &&
            Schema::hasColumn('business_inventory_settings', 'allow_free_stock')
        ) {
            return (bool) DB::table('business_inventory_settings')
                ->where('business_id', AppController::businessId())
                ->value('allow_free_stock');
        }

        return false;
    }
}
