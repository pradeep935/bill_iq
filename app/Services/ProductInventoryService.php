<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\HsnMaster;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductCategory;
use App\Models\StockLedger;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductInventoryService
{
    public function businessId(): int
    {
        if (session('business_id') || session('company_id') || session('tenant_id')) {
            return (int) (session('business_id') ?: session('company_id') ?: session('tenant_id'));
        }

        if (Auth::user()?->tenant_id) {
            return (int) Auth::user()->tenant_id;
        }

        if (Schema::hasTable('companies')) {
            return (int) (DB::table('companies')->value('id') ?: DB::table('companies')->insertGetId([
                'name' => 'ABC Retail Pvt Ltd',
                'state' => 'Noida',
                'financial_year' => '2026-27',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        return (int) (DB::table('tenants')->value('id') ?: 1);
    }

    public function listProducts(int $businessId)
    {
        if (!Schema::hasColumn('products', 'business_id')) {
            return Product::query()
                ->where('tenant_id', $businessId)
                ->latest('id')
                ->get()
                ->map(fn (Product $product) => $this->presentLegacy($product));
        }

        return Product::query()
            ->with(['hsn', 'category', 'brand', 'unit', 'barcodes'])
            ->where(function ($query) use ($businessId) {
                $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->latest('id')
            ->get()
            ->map(fn (Product $product) => $this->present($product));
    }

    public function validatePayload(Request $request, ?Product $product = null): array
    {
        $businessId = $this->businessId();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:120'],
            'product_type' => ['required', Rule::in(['goods', 'service', 'combo'])],
            'sku' => [
                'required',
                'string',
                'max:80',
                Rule::unique('products', 'sku')->ignore($product?->id)->where(fn ($query) => $query->where(Schema::hasColumn('products', 'business_id') ? 'business_id' : 'tenant_id', $businessId)),
            ],
            'description' => ['nullable', 'string'],
            'category_name' => ['nullable', 'string', 'max:120'],
            'brand_name' => ['nullable', 'string', 'max:120'],
            'unit_code' => ['required', 'string', 'max:12'],
            'hsn_code' => ['required', 'string', 'regex:/^\d{4}(\d{2})?(\d{2})?$/'],
            'tax_inclusive' => ['boolean'],
            'track_inventory' => ['boolean'],
            'tracking_type' => ['required', Rule::in(['none', 'batch', 'serial', 'batch_serial'])],
            'has_expiry' => ['boolean'],
            'allow_negative_stock' => ['boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock' => ['nullable', 'numeric', 'min:0'],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
            'default_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'default_selling_price' => ['required', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'barcodes' => ['array'],
            'barcodes.*.barcode' => ['required_with:barcodes', 'string', 'max:80'],
            'opening_stock.quantity' => ['nullable', 'numeric', 'min:0'],
            'opening_stock.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if ((float) $request->input('mrp') > 0 && (float) $request->input('default_selling_price') > (float) $request->input('mrp')) {
                $validator->errors()->add('default_selling_price', 'Selling price cannot exceed MRP without authorized override.');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    public function save(Request $request, ?Product $product = null): Product
    {
        $data = $this->validatePayload($request, $product);
        $businessId = $this->businessId();
        $userId = Auth::id();

        return DB::transaction(function () use ($data, $businessId, $userId, $product) {
            if (!Schema::hasColumn('products', 'business_id')) {
                return $this->saveLegacy($data, $businessId, $product);
            }

            $category = $this->category($businessId, $data['category_name'] ?? null);
            $brand = $this->brand($businessId, $data['brand_name'] ?? null);
            $unit = Unit::query()->firstOrCreate(
                ['code' => strtoupper($data['unit_code'])],
                ['name' => strtoupper($data['unit_code']), 'status' => 'active']
            );
            $hsn = HsnMaster::query()->firstOrCreate(
                ['hsn_code' => $data['hsn_code']],
                [
                    'description' => 'HSN ' . $data['hsn_code'],
                    'gst_rate' => 0,
                    'cess_rate' => 0,
                    'status' => 'active',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );
            $rate = $hsn->currentTaxRate() ?: $hsn;
            $opening = $data['opening_stock'] ?? [];

            $attributes = [
                'business_id' => $businessId,
                'company_id' => $businessId,
                'product_type' => $data['product_type'],
                'name' => $data['name'],
                'short_name' => $data['short_name'] ?? null,
                'sku' => $data['sku'],
                'description' => $data['description'] ?? null,
                'category_id' => $category?->id,
                'brand_id' => $brand?->id,
                'unit_id' => $unit->id,
                'hsn' => $hsn->hsn_code,
                'hsn_id' => $hsn->id,
                'gst_rate' => $rate->gst_rate,
                'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? false),
                'track_inventory' => (bool) ($data['track_inventory'] ?? true),
                'tracking_type' => $data['tracking_type'],
                'has_expiry' => (bool) ($data['has_expiry'] ?? false),
                'allow_negative_stock' => (bool) ($data['allow_negative_stock'] ?? false),
                'reorder_level' => $data['reorder_level'] ?? 0,
                'minimum_stock' => $data['minimum_stock'] ?? 0,
                'maximum_stock' => $data['maximum_stock'] ?? 0,
                'safety_stock' => $data['safety_stock'] ?? 0,
                'purchase_price' => $data['default_purchase_price'] ?? 0,
                'sale_price' => $data['default_selling_price'],
                'default_purchase_price' => $data['default_purchase_price'] ?? 0,
                'default_selling_price' => $data['default_selling_price'],
                'mrp' => $data['mrp'] ?? null,
                'status' => $data['status'],
                'updated_by' => $userId,
            ];

            if (!$product) {
                $attributes['created_by'] = $userId;
                $product = Product::query()->create($attributes);
            } else {
                $product->update($attributes);
            }

            $this->syncBarcodes($businessId, $product, $data['barcodes'] ?? []);

            if (!$product->wasRecentlyCreated) {
                $this->audit('product_master', $product->id, 'updated', 'Product updated');
            } else {
                $this->audit('product_master', $product->id, 'created', 'Product created');
            }

            if ($product->wasRecentlyCreated && (float) ($opening['quantity'] ?? 0) > 0) {
                $this->postOpeningStock($businessId, $product, $opening);
            }

            return $product->fresh(['hsn', 'category', 'brand', 'unit', 'barcodes']);
        });
    }

    public function present(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'invoiceName' => $product->short_name ?: $product->name,
            'type' => ucfirst($product->product_type ?: 'goods'),
            'category' => $product->category?->name,
            'brand' => $product->brand?->name,
            'sku' => $product->sku,
            'hsn' => $product->hsn,
            'taxability' => ((float) $product->gst_rate > 0) ? 'Taxable' : 'Nil Rated',
            'gstRate' => (float) $product->gst_rate,
            'cessRate' => (float) ($product->hsn?->cess_rate ?? 0),
            'reverseCharge' => 'No',
            'unit' => $product->unit?->code ?: 'PCS',
            'variant' => $product->short_name,
            'mrp' => (float) $product->mrp,
            'salePrice' => (float) ($product->default_selling_price ?: $product->sale_price),
            'costPrice' => (float) ($product->default_purchase_price ?: $product->purchase_price),
            'stock' => (float) ($product->current_stock ?? 0),
            'reorderLevel' => (float) ($product->reorder_level ?? 0),
            'trackingType' => str($product->tracking_type ?: 'none')->replace('_', ' ')->title()->toString(),
            'status' => ucfirst($product->status ?: 'active'),
            'barcodes' => $product->barcodes->map(fn (ProductBarcode $barcode) => [
                'code' => $barcode->barcode,
                'type' => ucfirst($barcode->type),
                'isPrimary' => $barcode->is_primary,
                'qty' => (float) $barcode->quantity,
            ])->all(),
        ];
    }

    private function presentLegacy(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'invoiceName' => $product->name,
            'type' => ucfirst($product->product_type ?: 'goods'),
            'category' => $product->category,
            'brand' => $product->brand,
            'sku' => $product->sku,
            'hsn' => $product->hsn,
            'taxability' => ((float) $product->gst_rate > 0) ? 'Taxable' : 'Nil Rated',
            'gstRate' => (float) $product->gst_rate,
            'cessRate' => 0,
            'reverseCharge' => 'No',
            'unit' => $product->unit ?: 'PCS',
            'variant' => null,
            'mrp' => (float) $product->mrp,
            'salePrice' => (float) $product->selling_price,
            'costPrice' => (float) $product->cost_price,
            'stock' => (float) ($product->metadata['current_stock'] ?? 0),
            'reorderLevel' => (float) ($product->min_stock ?? 0),
            'trackingType' => 'None',
            'status' => ucfirst($product->status ?: 'active'),
            'barcodes' => array_values(array_filter([
                $product->barcode ? ['code' => $product->barcode, 'type' => 'Primary', 'isPrimary' => true, 'qty' => 1] : null,
            ])),
        ];
    }

    private function saveLegacy(array $data, int $businessId, ?Product $product = null): Product
    {
        $barcode = collect($data['barcodes'] ?? [])->first()['barcode'] ?? null;
        $attributes = [
            'tenant_id' => $businessId,
            'name' => $data['name'],
            'sku' => $data['sku'],
            'barcode' => $barcode,
            'category' => $data['category_name'] ?? null,
            'brand' => $data['brand_name'] ?? null,
            'hsn' => $data['hsn_code'],
            'unit' => strtoupper($data['unit_code']),
            'product_type' => $data['product_type'],
            'gst_rate' => 0,
            'cost_price' => $data['default_purchase_price'] ?? 0,
            'selling_price' => $data['default_selling_price'],
            'mrp' => $data['mrp'] ?? null,
            'min_stock' => $data['minimum_stock'] ?? $data['reorder_level'] ?? 0,
            'status' => $data['status'],
            'metadata' => [
                'tracking_type' => $data['tracking_type'],
                'track_inventory' => $data['track_inventory'] ?? true,
                'has_expiry' => $data['has_expiry'] ?? false,
                'current_stock' => (float) ($data['opening_stock']['quantity'] ?? 0),
            ],
        ];

        if (!$product) {
            $product = Product::query()->create($attributes);
            $this->audit('product_master', $product->id, 'created', 'Product created');
            return $product->fresh();
        }

        $product->update($attributes);
        $this->audit('product_master', $product->id, 'updated', 'Product updated');

        return $product->fresh();
    }

    private function category(int $businessId, ?string $name): ?ProductCategory
    {
        if (!$name) return null;

        return ProductCategory::query()->firstOrCreate(['business_id' => $businessId, 'name' => $name], ['status' => 'active']);
    }

    private function brand(int $businessId, ?string $name): ?Brand
    {
        if (!$name) return null;

        return Brand::query()->firstOrCreate(['business_id' => $businessId, 'name' => $name], ['status' => 'active']);
    }

    private function syncBarcodes(int $businessId, Product $product, array $barcodes): void
    {
        $product->barcodes()->delete();

        foreach ($barcodes as $index => $barcode) {
            ProductBarcode::query()->create([
                'business_id' => $businessId,
                'product_id' => $product->id,
                'barcode' => $barcode['barcode'],
                'type' => $barcode['type'] ?? ($index === 0 ? 'manufacturer' : 'internal'),
                'quantity' => $barcode['quantity'] ?? 1,
                'is_primary' => (bool) ($barcode['is_primary'] ?? $index === 0),
                'status' => 'active',
            ]);
        }
    }

    private function postOpeningStock(int $businessId, Product $product, array $opening): void
    {
        $quantity = (float) ($opening['quantity'] ?? 0);
        $unitCost = (float) ($opening['unit_cost'] ?? 0);
        $stockValue = $quantity * $unitCost;

        $entry = OpeningStockEntry::query()->create([
            'business_id' => $businessId,
            'branch_id' => $opening['branch_id'] ?? null,
            'warehouse_id' => $opening['warehouse_id'] ?? null,
            'entry_date' => now()->toDateString(),
            'status' => 'approved',
            'created_by' => Auth::id(),
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'remarks' => 'Opening stock from product master',
        ]);

        OpeningStockItem::query()->create([
            'opening_stock_entry_id' => $entry->id,
            'business_id' => $businessId,
            'branch_id' => $entry->branch_id,
            'warehouse_id' => $entry->warehouse_id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'stock_value' => $stockValue,
        ]);

        StockLedger::query()->create([
            'business_id' => $businessId,
            'branch_id' => $entry->branch_id,
            'warehouse_id' => $entry->warehouse_id,
            'product_id' => $product->id,
            'transaction_type' => 'opening_stock',
            'reference_type' => OpeningStockEntry::class,
            'reference_id' => $entry->id,
            'quantity_in' => $quantity,
            'unit_cost' => $unitCost,
            'stock_value' => $stockValue,
            'remarks' => 'Opening stock approved',
        ]);

        $product->increment('current_stock', $quantity);
        $product->update(['opening_stock' => $quantity]);
        $this->audit('opening_stock', $entry->id, 'approved', 'Opening stock posted');
    }

    private function audit(string $module, int $recordId, string $action, string $summary): void
    {
        try {
            DB::table('audit_logs')->insert([
                'client_id' => $this->businessId(),
                'module_name' => $module,
                'record_id' => (string) $recordId,
                'action_type' => $action,
                'changed_by_user_id' => Auth::id(),
                'changed_by_name' => Auth::user()?->name,
                'user_role' => (string) (Auth::user()?->role_id ?? ''),
                'ip_address' => request()->ip(),
                'summary' => $summary,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            report(new \RuntimeException("Audit log skipped for {$module}:{$recordId}:{$action}"));
        }
    }
}
