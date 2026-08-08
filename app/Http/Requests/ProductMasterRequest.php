<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use App\Models\HsnMaster;
use App\Models\HsnTaxRate;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariantItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
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
        foreach (['category_id', 'sub_category_id', 'brand_id', 'unit_id', 'hsn_master_id', 'hsn_id', 'hsn_tax_rate_id'] as $field) {
            $value = $this->input($field);

            if ($value === '' || $value === null || !ctype_digit((string) $value)) {
                $this->merge([$field => null]);
            }
        }

        foreach (['name', 'product_name', 'short_name', 'sku', 'primary_barcode', 'hsn_code', 'category', 'subcategory', 'brand', 'unit', 'variant'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if (in_array($this->input('taxability'), ['exempt', 'non_gst', 'nil_rated'], true)) {
            $this->merge([
                'gst_rate' => 0,
            ]);
        }

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
        $isTaxable = !in_array($this->input('taxability'), ['exempt', 'non_gst', 'nil_rated'], true);

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
            'hsn_tax_rate_id' => ['nullable', 'integer', 'exists:hsn_tax_rates,id'],
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
                    ->whereNull('deleted_at')
                    ->ignore($productId),
            ],
            'primary_barcode' => [
                'nullable',
                'string',
                'max:100',
            ],
            'extra_barcodes' => ['nullable', 'string'],
            'hsn_code' => [$isTaxable ? 'required' : 'nullable', 'string', 'max:20'],
            'taxability' => ['required', Rule::in(['taxable', 'exempt', 'nil_rated', 'non_gst'])],
            'gst_rate' => [$isTaxable ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100'],
            'cess_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_source' => ['nullable', Rule::in(['verified_rule', 'manual_confirmation', 'override'])],
            'tax_override_reason' => ['nullable', 'string', 'max:2000'],
            'tax_override_reference' => ['nullable', 'string', 'max:255'],
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
            'status' => ['required', Rule::in(['active', 'inactive', 'discontinued'])],

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

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter the product name.',
            'product_type.required' => 'Please select product type: Goods or Service.',
            'product_type.in' => 'Product type must be Goods or Service.',
            'item_type.required' => 'Please select whether this is a stock item or non-stock item.',
            'unit.required' => 'Please select the unit used for billing and stock.',
            'sku.required' => 'Please enter a unique SKU/item code.',
            'sku.unique' => 'This SKU already exists in this business. Please use a different SKU.',
            'category_id.integer' => 'Please select a valid category from the list.',
            'category_id.exists' => 'Selected category was not found or is inactive.',
            'sub_category_id.integer' => 'Please select a valid sub category from the list.',
            'sub_category_id.exists' => 'Selected sub category was not found or is inactive.',
            'brand_id.integer' => 'Please select a valid brand from the list.',
            'brand_id.exists' => 'Selected brand was not found or is inactive.',
            'hsn_code.required' => 'Please enter or select HSN/SAC code for taxable products.',
            'hsn_master_id.exists' => 'Selected HSN/SAC master was not found.',
            'taxability.required' => 'Please select taxability.',
            'gst_rate.required' => 'Please select GST rate for taxable products.',
            'gst_rate.numeric' => 'GST rate must be a valid number.',
            'gst_rate.max' => 'GST rate cannot be more than 100%.',
            'selling_price.required' => 'Please enter the selling price.',
            'selling_price.numeric' => 'Selling price must be a valid number.',
            'selling_price.min' => 'Selling price cannot be negative.',
            '*.numeric' => ':attribute must be a valid number.',
            '*.min' => ':attribute cannot be negative.',
            'tracking_type.required' => 'Please select inventory tracking method.',
            'tracking_type.in' => 'Please select a valid inventory tracking method.',
            'status.required' => 'Please select product status.',
            'status.in' => 'Please select a valid product status.',
            'barcodes.*.barcode.distinct' => 'Duplicate barcodes are not allowed.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'product name',
            'product_type' => 'product type',
            'item_type' => 'item type',
            'category_id' => 'category',
            'sub_category_id' => 'sub category',
            'brand_id' => 'brand',
            'unit' => 'unit',
            'sku' => 'SKU',
            'primary_barcode' => 'primary barcode',
            'hsn_code' => 'HSN/SAC code',
            'gst_rate' => 'GST rate',
            'cess_rate' => 'cess rate',
            'cost_price' => 'cost price',
            'selling_price' => 'selling price',
            'mrp' => 'MRP',
            'wholesale_price' => 'wholesale price',
            'dealer_price' => 'dealer price',
            'online_price' => 'online price',
            'minimum_stock' => 'minimum stock',
            'reorder_stock' => 'reorder stock',
            'maximum_stock' => 'maximum stock',
            'tracking_type' => 'tracking method',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $businessId = AppController::businessId();
            $productId = (int) ($this->input('id') ?: $this->route('product') ?: 0);

            if (
                $this->filled('mrp') &&
                (float) $this->input('mrp') > 0 &&
                (float) $this->input('selling_price') > (float) $this->input('mrp')
            ) {
                $validator->errors()->add('selling_price', 'Selling price cannot be greater than MRP.');
            }

            foreach ([
                'wholesale_price' => 'Wholesale price',
                'dealer_price' => 'Dealer price',
                'online_price' => 'Online price',
            ] as $field => $label) {
                if (
                    $this->filled('mrp') &&
                    (float) $this->input('mrp') > 0 &&
                    $this->filled($field) &&
                    (float) $this->input($field) > (float) $this->input('mrp')
                ) {
                    $validator->errors()->add($field, "{$label} cannot be greater than MRP.");
                }
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

            if ($this->input('product_type') !== 'service') {
                $minimumStock = (float) ($this->input('minimum_stock') ?? 0);
                $reorderStock = (float) ($this->input('reorder_stock') ?? 0);
                $maximumStock = (float) ($this->input('maximum_stock') ?? 0);

                if ($reorderStock > 0 && $minimumStock > 0 && $reorderStock < $minimumStock) {
                    $validator->errors()->add('reorder_stock', 'Reorder stock should be equal to or greater than minimum stock.');
                }

                if ($maximumStock > 0 && $minimumStock > 0 && $maximumStock < $minimumStock) {
                    $validator->errors()->add('maximum_stock', 'Maximum stock should be equal to or greater than minimum stock.');
                }

                if ($maximumStock > 0 && $reorderStock > 0 && $maximumStock < $reorderStock) {
                    $validator->errors()->add('maximum_stock', 'Maximum stock should be equal to or greater than reorder stock.');
                }
            }

            if ($this->filled('hsn_master_id')) {
                $expectedCodeType = $this->input('product_type') === 'service' ? 'SAC' : 'HSN';
                $hsn = HsnMaster::query()
                    ->where('status', 'active')
                    ->where(function ($query) use ($businessId) {
                        $query->whereNull('business_id')->orWhere('business_id', $businessId);
                    })
                    ->find($this->input('hsn_master_id'));

                if (!$hsn) {
                    $validator->errors()->add('hsn_master_id', 'Selected HSN is not active.');
                } elseif ($hsn->code_type && $hsn->code_type !== $expectedCodeType) {
                    $validator->errors()->add('hsn_master_id', "Select a valid {$expectedCodeType} record for this product type.");
                } elseif ($this->filled('hsn_code') && $this->input('hsn_code') !== $hsn->hsn_code) {
                    $validator->errors()->add('hsn_code', 'HSN code does not match selected HSN master.');
                }

                if ($this->filled('hsn_tax_rate_id')) {
                    $rule = HsnTaxRate::query()
                        ->where('hsn_id', $this->input('hsn_master_id'))
                        ->where('status', 'active')
                        ->where('verification_status', 'verified')
                        ->find($this->input('hsn_tax_rate_id'));

                    if (!$rule) {
                        $validator->errors()->add('hsn_tax_rate_id', 'Selected GST rule is not a verified active rule for this HSN/SAC.');
                    }
                }
            }

            if ($this->input('tax_source') === 'override') {
                $user = $this->user();

                if (!$user || !($user->isSuperAdmin() || $user->isAdmin())) {
                    $validator->errors()->add('tax_source', 'Only authorized users can override GST.');
                }

                if (!$this->filled('tax_override_reason')) {
                    $validator->errors()->add('tax_override_reason', 'GST override reason is required.');
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
        return Product::query()
            ->where('sku', $sku)
            ->where('id', '!=', $productId)
            ->where(fn ($query) => $this->whereProductBusiness($query, $businessId))
            ->exists();
    }

    private function barcodeExistsInBusiness(string $barcode, int $businessId, int $productId): bool
    {
        $productBarcodeExists = Product::query()
            ->where('id', '!=', $productId)
            ->where(fn ($query) => $this->whereProductBusiness($query, $businessId))
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

    private function whereProductBusiness($query, int $businessId): void
    {
        $columns = array_values(array_filter(
            ['business_id', 'company_id', 'tenant_id'],
            fn ($column) => Schema::hasColumn('products', $column)
        ));

        if (!$columns) {
            return;
        }

        $query->where($columns[0], $businessId);

        foreach (array_slice($columns, 1) as $column) {
            $query->orWhere($column, $businessId);
        }
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
