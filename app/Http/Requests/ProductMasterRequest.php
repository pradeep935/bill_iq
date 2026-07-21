<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use App\Models\HsnMaster;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariantItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->isAdmin());
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('product_type') !== 'service') {
            return;
        }

        $this->merge([
            'item_type' => 'non_stock',
            'opening_stock' => 0,
            'minimum_stock' => 0,
            'reorder_stock' => 0,
            'maximum_stock' => 0,
            'tracking_type' => 'none',
            'expiry_required' => false,
            'batch_required' => false,
            'serial_required' => false,
        ]);
    }

    public function rules(): array
    {
        $businessId = AppController::businessId();
        $productId = (int) ($this->input('id') ?: $this->route('product') ?: 0);

        return [
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:150'],
            'product_type' => ['required', Rule::in(['goods', 'service'])],
            'item_type' => ['required', Rule::in(['stock', 'non_stock'])],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')->where(function ($query) use ($businessId) {
                    $query->whereNull('business_id')->orWhere('business_id', $businessId);
                }),
            ],
            'sub_category_id' => [
                'nullable',
                'integer',
                Rule::exists('product_categories', 'id')->where(function ($query) use ($businessId) {
                    $query->whereNull('business_id')->orWhere('business_id', $businessId);
                }),
            ],
            'brand_id' => [
                'nullable',
                'integer',
                Rule::exists('brands', 'id')->where(function ($query) use ($businessId) {
                    $query->whereNull('business_id')->orWhere('business_id', $businessId);
                }),
            ],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'hsn_master_id' => ['nullable', 'integer', 'exists:hsn_masters,id'],
            'hsn_id' => ['nullable', 'integer', 'exists:hsn_masters,id'],
            'category' => ['nullable', 'string', 'max:150'],
            'subcategory' => ['nullable', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:30'],
            'variant' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sku' => [
                'required',
                'string',
                'max:100',
                Rule::unique('products', 'sku')
                    ->where('business_id', $businessId)
                    ->ignore($productId),
            ],
            'primary_barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'primary_barcode')
                    ->where('business_id', $businessId)
                    ->ignore($productId),
            ],
            'extra_barcodes' => ['nullable', 'string'],
            'hsn_code' => ['required', 'string', 'max:20'],
            'taxability' => ['required', Rule::in(['taxable', 'exempt', 'nil_rated', 'non_gst'])],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reverse_charge' => ['required', Rule::in(['yes', 'no'])],
            'tax_inclusive' => ['nullable', 'boolean'],
            'invoice_description' => ['nullable', 'string', 'max:500'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'dealer_price' => ['nullable', 'numeric', 'min:0'],
            'online_price' => ['nullable', 'numeric', 'min:0'],
            'opening_stock' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock' => ['nullable', 'numeric', 'min:0'],
            'tracking_type' => ['required', Rule::in(['none', 'batch', 'batch_expiry', 'serial', 'imei'])],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'expiry_required' => ['nullable', 'boolean'],
            'batch_required' => ['nullable', 'boolean'],
            'serial_required' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],

            'barcodes' => ['nullable', 'array'],
            'barcodes.*.barcode' => ['required_with:barcodes', 'string', 'max:100', 'distinct'],
            'barcodes.*.barcode_type' => ['nullable', 'string', 'max:30'],
            'barcodes.*.is_primary' => ['nullable', 'boolean'],
            'prices' => ['nullable', 'array'],
            'prices.*.price_type' => ['required_with:prices', 'string', 'max:40'],
            'prices.*.price' => ['required_with:prices', 'numeric', 'min:0'],
            'images' => ['nullable', 'array'],
            'images.*.image_path' => ['nullable', 'string', 'max:255'],
            'images.*.image_type' => ['nullable', 'string', 'max:30'],
            'images.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'images.*.is_primary' => ['nullable', 'boolean'],
            'variants' => ['nullable', 'array'],
            'variants.*.variant_name' => ['required_with:variants', 'string', 'max:100'],
            'variants.*.values' => ['nullable', 'array'],
            'variants.*.values.*' => ['nullable', 'string', 'max:100'],
            'variant_items' => ['nullable', 'array'],
            'variant_items.*.sku' => ['required_with:variant_items', 'string', 'max:100'],
            'variant_items.*.barcode' => ['nullable', 'string', 'max:100'],
            'variant_items.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'variant_items.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'variant_items.*.mrp' => ['nullable', 'numeric', 'min:0'],
            'variant_items.*.current_stock' => ['nullable', 'numeric', 'min:0'],
            'batches' => ['nullable', 'array'],
            'batches.*.batch_no' => ['required_with:batches', 'string', 'max:100'],
            'batches.*.manufacturing_date' => ['nullable', 'date'],
            'batches.*.expiry_date' => ['nullable', 'date'],
            'batches.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'batches.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'batches.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'serials' => ['nullable', 'array'],
            'serials.*.serial_number' => ['required_with:serials', 'string', 'max:100'],
            'serials.*.status' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $businessId = AppController::businessId();
            $productId = (int) ($this->input('id') ?: $this->route('product') ?: 0);

            if (
                $this->filled('mrp') &&
                (float) $this->input('selling_price') > (float) $this->input('mrp')
            ) {
                $validator->errors()->add('selling_price', 'Selling price cannot be greater than MRP.');
            }

            foreach ((array) $this->input('variant_items', []) as $index => $item) {
                if (
                    isset($item['mrp'], $item['selling_price']) &&
                    $item['mrp'] !== null &&
                    (float) $item['selling_price'] > (float) $item['mrp']
                ) {
                    $validator->errors()->add("variant_items.$index.selling_price", 'Variant selling price cannot be greater than MRP.');
                }
            }

            if ($this->filled('hsn_master_id')) {
                $hsn = HsnMaster::query()
                    ->where('status', 'active')
                    ->find($this->input('hsn_master_id'));

                if (!$hsn) {
                    $validator->errors()->add('hsn_master_id', 'Selected HSN is not active.');
                } elseif ($this->filled('hsn_code') && $this->input('hsn_code') !== $hsn->hsn_code) {
                    $validator->errors()->add('hsn_code', 'HSN code does not match selected HSN master.');
                }
            }

            if ($this->skuExistsInBusiness((string) $this->input('sku'), $businessId, $productId)) {
                $validator->errors()->add('sku', 'SKU already exists for this business.');
            }

            $barcodes = $this->normalizedBarcodes();

            if (count($barcodes) !== count(array_unique($barcodes))) {
                $validator->errors()->add('barcodes', 'Duplicate barcodes are not allowed.');
            }

            foreach (array_unique($barcodes) as $barcode) {
                if ($this->barcodeExistsInBusiness($barcode, $businessId, $productId)) {
                    $validator->errors()->add('barcodes', "Barcode {$barcode} already exists for this business.");
                }
            }

            foreach ((array) $this->input('variant_items', []) as $index => $item) {
                $sku = trim((string) ($item['sku'] ?? ''));

                if ($sku === '') {
                    continue;
                }

                if ($this->variantSkuExistsInBusiness($sku, $businessId, $productId)) {
                    $validator->errors()->add("variant_items.$index.sku", 'Variant SKU already exists for this business.');
                }
            }
        });
    }

    private function normalizedBarcodes(): array
    {
        $barcodes = [];
        $primaryBarcode = trim((string) $this->input('primary_barcode'));

        if ($primaryBarcode !== '') {
            $barcodes[] = $primaryBarcode;
        }

        foreach (explode(',', (string) $this->input('extra_barcodes')) as $barcode) {
            $barcode = trim($barcode);

            if ($barcode !== '' && $barcode !== $primaryBarcode) {
                $barcodes[] = $barcode;
            }
        }

        foreach ((array) $this->input('barcodes', []) as $barcodeRow) {
            $barcode = trim((string) ($barcodeRow['barcode'] ?? ''));
            $isPrimaryRow = (bool) ($barcodeRow['is_primary'] ?? false);

            if ($barcode !== '' && !($isPrimaryRow && $barcode === $primaryBarcode)) {
                $barcodes[] = $barcode;
            }
        }

        return array_values(array_filter($barcodes));
    }

    private function skuExistsInBusiness(string $sku, int $businessId, int $productId): bool
    {
        return Product::withTrashed()
            ->where('sku', $sku)
            ->where('id', '!=', $productId)
            ->where(function ($query) use ($businessId) {
                $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->exists();
    }

    private function barcodeExistsInBusiness(string $barcode, int $businessId, int $productId): bool
    {
        $productBarcodeExists = Product::withTrashed()
            ->where('id', '!=', $productId)
            ->where(function ($query) use ($businessId) {
                $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->where(function ($query) use ($barcode) {
                $query
                    ->where('primary_barcode', $barcode)
                    ->orWhere('barcode', $barcode)
                    ->orWhere('extra_barcodes', 'like', '%' . $barcode . '%');
            })
            ->exists();

        if ($productBarcodeExists) {
            return true;
        }

        return ProductBarcode::query()
            ->where('barcode', $barcode)
            ->where('product_id', '!=', $productId)
            ->where('business_id', $businessId)
            ->exists();
    }

    private function variantSkuExistsInBusiness(string $sku, int $businessId, int $productId): bool
    {
        return ProductVariantItem::withTrashed()
            ->where('sku', $sku)
            ->where('product_id', '!=', $productId)
            ->where('business_id', $businessId)
            ->exists();
    }
}
