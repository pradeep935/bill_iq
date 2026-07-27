<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductBatch;
use App\Models\HsnMaster;
use App\Models\ProductImage;
use App\Models\ProductPrice;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\ProductVariantItem;
use App\Models\ProductVariantValue;
use App\Services\MasterDataService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductMasterService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $businessId = $this->businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $this->baseQuery($businessId)
            ->when(!empty($filters['search']), function (Builder $query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function (Builder $inner) use ($search) {
                    foreach (['name', 'product_name', 'sku', 'primary_barcode', 'barcode', 'hsn_code', 'hsn'] as $column) {
                        if (Schema::hasColumn('products', $column)) {
                            $inner->orWhere($column, 'like', '%' . $search . '%');
                        }
                    }

                    if (Schema::hasTable('product_barcodes')) {
                        $inner->orWhereHas('barcodes', function (Builder $barcodeQuery) use ($search) {
                            $barcodeQuery->where('barcode', 'like', '%' . $search . '%');
                        });
                    }
                });
            })
            ->when(!empty($filters['category']), function (Builder $query) use ($filters) {
                $query->where(function (Builder $inner) use ($filters) {
                    if (Schema::hasColumn('products', 'category')) {
                        $inner->where('category', $filters['category']);
                    }

                    if (is_numeric($filters['category']) && Schema::hasColumn('products', 'category_id')) {
                        $inner->orWhere('category_id', (int) $filters['category']);
                    }
                });
            })
            ->when(!empty($filters['brand']), function (Builder $query) use ($filters) {
                $query->where(function (Builder $inner) use ($filters) {
                    if (Schema::hasColumn('products', 'brand')) {
                        $inner->where('brand', $filters['brand']);
                    }

                    if (is_numeric($filters['brand']) && Schema::hasColumn('products', 'brand_id')) {
                        $inner->orWhere('brand_id', (int) $filters['brand']);
                    }
                });
            })
            ->when(!empty($filters['unit']), function (Builder $query) use ($filters) {
                $query->where(function (Builder $inner) use ($filters) {
                    if (Schema::hasColumn('products', 'unit')) {
                        $inner->where('unit', $filters['unit']);
                    }

                    if (is_numeric($filters['unit']) && Schema::hasColumn('products', 'unit_id')) {
                        $inner->orWhere('unit_id', (int) $filters['unit']);
                    }
                });
            })
            ->when(!empty($filters['product_type']) && Schema::hasColumn('products', 'product_type'), fn (Builder $query) => $query->where('product_type', $filters['product_type']))
            ->when(!empty($filters['item_type']) && Schema::hasColumn('products', 'item_type'), fn (Builder $query) => $query->where('item_type', $filters['item_type']))
            ->when(isset($filters['gst_rate']) && $filters['gst_rate'] !== '', fn (Builder $query) => $query->where('gst_rate', $filters['gst_rate']))
            ->when(!empty($filters['status']), function (Builder $query) use ($filters) {
                if ($filters['status'] === 'deleted') {
                    if (Schema::hasColumn('products', 'deleted_at')) {
                        $query->onlyTrashed();
                    }
                    return;
                }

                if ($filters['status'] !== 'all') {
                    $query->where('status', $filters['status']);
                }
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function find(int $productId, bool $withTrashed = false): Product
    {
        $query = $this->baseQuery($this->businessId());

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->where('id', $productId)->firstOrFail();
    }

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = new Product();
            $this->fillProduct($product, $data);
            $this->setProductColumn($product, 'created_by', Auth::id());
            $product->save();
            $this->syncChildren($product, $data);

            return $this->freshProduct($product);
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $this->fillProduct($product, $data);
            $product->save();
            $this->syncChildren($product, $data);

            return $this->freshProduct($product);
        });
    }

    public function duplicate(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $copy = $product->replicate();
            $copy->name = $product->name . ' Copy';
            $copy->product_name = ($product->product_name ?: $product->name) . ' Copy';
            $copy->sku = $this->uniqueSku($product->sku);
            $copy->barcode = null;
            $copy->primary_barcode = null;
            $copy->extra_barcodes = null;
            $copy->status = 'inactive';
            $this->setProductColumn($copy, 'created_by', Auth::id());
            $this->setProductColumn($copy, 'updated_by', Auth::id());
            $copy->save();

            foreach ($product->prices as $price) {
                $copy->prices()->create(Arr::except($price->toArray(), ['id', 'product_id', 'created_at', 'updated_at', 'deleted_at']));
            }

            foreach ($product->images as $image) {
                $copy->images()->create(Arr::except($image->toArray(), ['id', 'product_id', 'created_at', 'updated_at', 'deleted_at']));
            }

            foreach ($product->variants as $variant) {
                $variantCopy = $copy->variants()->create(Arr::except($variant->toArray(), ['id', 'product_id', 'created_at', 'updated_at', 'deleted_at']));

                foreach ($variant->values as $value) {
                    $variantCopy->values()->create(Arr::except($value->toArray(), ['id', 'variant_id', 'created_at', 'updated_at']));
                }
            }

            return $this->freshProduct($copy);
        });
    }

    public function softDelete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $this->deleteProductChildren($product);
            $product->delete();
        });
    }

    public function restore(Product $product): Product
    {
        $product->restore();

        return $this->freshProduct($product);
    }

    public function forceDelete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $this->deleteProductChildren($product);
            $product->forceDelete();
        });
    }

    public function bulkStatus(array $ids, string $status): int
    {
        $payload = [
            'status' => $status,
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('products', 'updated_by')) {
            $payload['updated_by'] = Auth::id();
        }

        return $this->baseQuery($this->businessId())
            ->whereIn('id', $ids)
            ->update($payload);
    }

    public function present(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'product_name' => $product->product_name ?: $product->name,
            'short_name' => $product->short_name,
            'product_type' => $product->product_type ?: 'goods',
            'item_type' => $product->item_type ?: 'stock',
            'category_id' => $product->category_id,
            'sub_category_id' => $product->sub_category_id,
            'brand_id' => $product->brand_id,
            'unit_id' => $product->unit_id,
            'hsn_id' => $product->hsn_id,
            'hsn_master_id' => $product->hsn_master_id ?: $product->hsn_id,
            'category' => $product->category,
            'subcategory' => $product->subcategory,
            'brand' => $product->brand,
            'variant' => $product->variant,
            'unit' => $product->unit ?: 'PCS',
            'description' => $product->description,
            'sku' => $product->sku,
            'primary_barcode' => $product->primary_barcode ?: $product->barcode,
            'extra_barcodes' => $product->extra_barcodes,
            'hsn_code' => $product->hsn_code ?: $product->getAttribute('hsn'),
            'taxability' => $product->taxability ?: (((float) $product->gst_rate > 0) ? 'taxable' : 'nil_rated'),
            'gst_rate' => (float) $product->gst_rate,
            'cess_rate' => (float) ($product->cess_rate ?: 0),
            'reverse_charge' => $product->reverse_charge ?: 'no',
            'tax_inclusive' => (bool) $product->tax_inclusive,
            'invoice_description' => $product->invoice_description,
            'cost_price' => (float) ($product->cost_price ?: $product->purchase_price ?: $product->default_purchase_price),
            'selling_price' => (float) ($product->selling_price ?: $product->sale_price ?: $product->default_selling_price),
            'mrp' => $product->mrp !== null ? (float) $product->mrp : null,
            'wholesale_price' => (float) ($product->wholesale_price ?: 0),
            'dealer_price' => (float) ($product->dealer_price ?: 0),
            'online_price' => (float) ($product->online_price ?: 0),
            'opening_stock' => (float) $product->opening_stock,
            'minimum_stock' => (float) ($product->minimum_stock ?: 0),
            'reorder_stock' => (float) ($product->reorder_stock ?: $product->reorder_level ?: 0),
            'maximum_stock' => (float) ($product->maximum_stock ?: 0),
            'tracking_type' => $product->tracking_type ?: 'none',
            'weight' => $product->weight !== null ? (float) $product->weight : null,
            'length' => $product->length !== null ? (float) $product->length : null,
            'width' => $product->width !== null ? (float) $product->width : null,
            'height' => $product->height !== null ? (float) $product->height : null,
            'expiry_required' => (bool) $product->expiry_required,
            'batch_required' => (bool) $product->batch_required,
            'serial_required' => (bool) $product->serial_required,
            'status' => $product->status ?: 'active',
            'deleted_at' => optional($product->deleted_at)->toDateTimeString(),
            'barcodes' => $this->relationValues($product, 'barcodes')->map(fn (ProductBarcode $barcode) => [
                'id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'barcode_type' => $barcode->barcode_type ?: $barcode->type,
                'is_primary' => (bool) $barcode->is_primary,
            ])->values(),
            'prices' => $this->relationValues($product, 'prices')->map(fn (ProductPrice $price) => [
                'id' => $price->id,
                'price_type' => $price->price_type,
                'price' => (float) $price->price,
            ])->values(),
            'images' => $this->relationValues($product, 'images')->map(fn (ProductImage $image) => [
                'id' => $image->id,
                'image_path' => $image->image_path,
                'image_type' => $image->image_type,
                'sort_order' => $image->sort_order,
                'is_primary' => (bool) $image->is_primary,
            ])->values(),
            'variants' => $this->relationValues($product, 'variants')->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'variant_name' => $variant->variant_name,
                'values' => $variant->values->pluck('value')->values(),
            ])->values(),
            'variant_items' => $this->relationValues($product, 'variantItems')->map(fn (ProductVariantItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'purchase_price' => (float) $item->purchase_price,
                'selling_price' => (float) $item->selling_price,
                'mrp' => $item->mrp !== null ? (float) $item->mrp : null,
                'current_stock' => (float) $item->current_stock,
            ])->values(),
            'batches' => $this->relationValues($product, 'batches')->map(fn (ProductBatch $batch) => [
                'id' => $batch->id,
                'batch_no' => $batch->batch_no ?: $batch->batch_number,
                'manufacturing_date' => optional($batch->manufacturing_date)->format('Y-m-d'),
                'expiry_date' => optional($batch->expiry_date)->format('Y-m-d'),
                'purchase_price' => (float) ($batch->purchase_price ?: $batch->cost_price),
                'selling_price' => (float) ($batch->selling_price ?: 0),
                'quantity' => (float) ($batch->quantity ?: 0),
            ])->values(),
            'serials' => $this->relationValues($product, 'serials')->map(fn (ProductSerial $serial) => [
                'id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'status' => $serial->status,
            ])->values(),
        ];
    }

    public function presentPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'products' => $paginator->getCollection()
                ->map(fn (Product $product) => $this->present($product))
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function references(): array
    {
        return app(MasterDataService::class)->references(['categories', 'sub_categories', 'brands', 'units', 'hsn_codes']);
    }

    private function fillProduct(Product $product, array $data): void
    {
        $businessId = $this->businessId();
        $name = $data['name'];
        $hsnId = $data['hsn_master_id'] ?? $data['hsn_id'] ?? null;
        $hsn = $hsnId ? HsnMaster::query()->find($hsnId) : null;
        $isService = $data['product_type'] === 'service';
        $itemType = $isService ? 'non_stock' : $data['item_type'];
        $trackingType = $isService ? 'none' : $data['tracking_type'];

        $this->setProductColumn($product, 'business_id', $businessId);
        $this->setProductColumn($product, 'company_id', $businessId);
        $this->setProductColumn($product, 'tenant_id', $businessId);

        $this->setProductColumn($product, 'name', $name);
        $this->setProductColumn($product, 'product_name', $data['product_name'] ?? $name);
        $this->setProductColumn($product, 'short_name', $data['short_name'] ?? null);
        $this->setProductColumn($product, 'product_type', $data['product_type']);
        $this->setProductColumn($product, 'item_type', $itemType);
        $this->setProductColumn($product, 'category_id', $data['category_id'] ?? null);
        $this->setProductColumn($product, 'sub_category_id', $data['sub_category_id'] ?? null);
        $brand = null;

        if (!empty($data['brand_id'])) {
            $brand = Brand::query()
                ->where(function (Builder $query) use ($businessId) {
                    $query->whereNull('business_id')->orWhere('business_id', $businessId);
                })
                ->find($data['brand_id']);
        }

        $this->setProductColumn($product, 'brand_id', $brand?->id);
        $this->setProductColumn($product, 'unit_id', $data['unit_id'] ?? null);
        $this->setProductColumn($product, 'hsn_id', $hsnId);
        $this->setProductColumn($product, 'hsn_master_id', $hsnId);
        $this->setProductColumn($product, 'category', $data['category'] ?? null);
        $this->setProductColumn($product, 'subcategory', $data['subcategory'] ?? null);
        $this->setProductColumn($product, 'brand', $brand?->name ?: ($data['brand'] ?? null));
        $this->setProductColumn($product, 'variant', $data['variant'] ?? null);
        $this->setProductColumn($product, 'unit', $data['unit']);
        $this->setProductColumn($product, 'description', $data['description'] ?? null);
        $this->setProductColumn($product, 'sku', $data['sku']);
        $this->setProductColumn($product, 'barcode', $data['primary_barcode'] ?? null);
        $this->setProductColumn($product, 'primary_barcode', $data['primary_barcode'] ?? null);
        $this->setProductColumn($product, 'extra_barcodes', $data['extra_barcodes'] ?? null);
        $hsnCode = $hsn ? $hsn->hsn_code : $data['hsn_code'];
        $this->setProductColumn($product, 'hsn', $hsnCode);
        $this->setProductColumn($product, 'hsn_code', $hsnCode);
        $this->setProductColumn($product, 'taxability', $data['taxability']);
        $this->setProductColumn($product, 'gst_rate', $data['gst_rate']);
        $this->setProductColumn($product, 'cess_rate', $data['cess_rate'] ?? 0);
        $this->setProductColumn($product, 'reverse_charge', $data['reverse_charge']);
        $this->setProductColumn($product, 'tax_inclusive', (bool) ($data['tax_inclusive'] ?? false));
        $this->setProductColumn($product, 'invoice_description', $data['invoice_description'] ?? null);
        $this->setProductColumn($product, 'purchase_price', $data['cost_price'] ?? 0);
        $this->setProductColumn($product, 'default_purchase_price', $data['cost_price'] ?? 0);
        $this->setProductColumn($product, 'cost_price', $data['cost_price'] ?? 0);
        $this->setProductColumn($product, 'sale_price', $data['selling_price']);
        $this->setProductColumn($product, 'default_selling_price', $data['selling_price']);
        $this->setProductColumn($product, 'selling_price', $data['selling_price']);
        $this->setProductColumn($product, 'mrp', $data['mrp'] === null || $data['mrp'] === '' ? 0 : $data['mrp']);
        $this->setProductColumn($product, 'wholesale_price', $data['wholesale_price'] ?? 0);
        $this->setProductColumn($product, 'dealer_price', $data['dealer_price'] ?? 0);
        $this->setProductColumn($product, 'online_price', $data['online_price'] ?? 0);
        $openingStock = $this->shouldStoreOpeningStockOnProduct()
            ? ($isService ? 0 : ($data['opening_stock'] ?? 0))
            : ($product->exists ? ($product->opening_stock ?? 0) : 0);
        $this->setProductColumn($product, 'opening_stock', $openingStock);
        $this->setProductColumn($product, 'minimum_stock', $isService ? 0 : ($data['minimum_stock'] ?? 0));
        $this->setProductColumn($product, 'reorder_stock', $isService ? 0 : ($data['reorder_stock'] ?? 0));
        $this->setProductColumn($product, 'reorder_level', $isService ? 0 : ($data['reorder_stock'] ?? $data['minimum_stock'] ?? 0));
        $this->setProductColumn($product, 'maximum_stock', $isService ? 0 : ($data['maximum_stock'] ?? 0));
        $this->setProductColumn($product, 'tracking_type', $trackingType);
        $this->setProductColumn($product, 'track_inventory', !$isService && $itemType === 'stock');
        $this->setProductColumn($product, 'weight', $data['weight'] ?? null);
        $this->setProductColumn($product, 'length', $data['length'] ?? null);
        $this->setProductColumn($product, 'width', $data['width'] ?? null);
        $this->setProductColumn($product, 'height', $data['height'] ?? null);
        $this->setProductColumn($product, 'expiry_required', !$isService && (bool) ($data['expiry_required'] ?? false));
        $this->setProductColumn($product, 'batch_required', !$isService && (bool) ($data['batch_required'] ?? false));
        $this->setProductColumn($product, 'serial_required', !$isService && (bool) ($data['serial_required'] ?? false));
        $this->setProductColumn($product, 'status', $data['status']);
        $this->setProductColumn($product, 'updated_by', Auth::id());
    }

    private function setProductColumn(Product $product, string $column, mixed $value): void
    {
        if (Schema::hasColumn('products', $column)) {
            $product->{$column} = $value;
        }
    }

    private function syncChildren(Product $product, array $data): void
    {
        $this->syncBarcodes($product, $data);
        $this->syncPrices($product, $data);
        $this->syncImages($product, $data['images'] ?? []);
        $this->syncVariants($product, $data['variants'] ?? []);
        $this->syncVariantItems($product, $data['variant_items'] ?? []);
        $this->syncBatches($product, $data['batches'] ?? []);
        $this->syncSerials($product, $data['serials'] ?? []);
    }

    private function deleteProductChildren(Product $product): void
    {
        $variantIds = Schema::hasTable('product_variants')
            ? $this->childBusinessQuery(ProductVariant::withTrashed(), $product, 'product_variants')
                ->where('product_id', $product->id)
                ->pluck('id')
                ->all()
            : [];

        if ($variantIds && Schema::hasTable('product_variant_values')) {
            DB::table('product_variant_values')
                ->whereIn('variant_id', $variantIds)
                ->delete();
        }

        foreach ([
            [ProductBarcode::query(), 'product_barcodes'],
            [ProductPrice::withTrashed(), 'product_prices'],
            [ProductImage::withTrashed(), 'product_images'],
            [ProductVariant::withTrashed(), 'product_variants'],
            [ProductVariantItem::withTrashed(), 'product_variant_items'],
            [ProductBatch::query(), 'product_batches'],
            [ProductSerial::withTrashed(), 'product_serials'],
        ] as [$query, $table]) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $this->deleteChildRows($this->childBusinessQuery($query, $product, $table)->where('product_id', $product->id), $table);
        }

        if (Schema::hasTable('product_serial_numbers')) {
            DB::table('product_serial_numbers')
                ->where('product_id', $product->id)
                ->delete();
        }
    }

    private function deleteChildRows(Builder $query, string $table): void
    {
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->forceDelete();
            return;
        }

        $query->delete();
    }

    private function syncBarcodes(Product $product, array $data): void
    {
        $barcodes = collect($data['barcodes'] ?? []);

        if (!empty($data['primary_barcode'])) {
            $barcodes->prepend([
                'barcode' => $data['primary_barcode'],
                'barcode_type' => 'primary',
                'is_primary' => true,
            ]);
        }

        collect(explode(',', (string) ($data['extra_barcodes'] ?? '')))
            ->map(fn (string $barcode) => trim($barcode))
            ->filter()
            ->each(fn (string $barcode) => $barcodes->push([
                'barcode' => $barcode,
                'barcode_type' => 'alternate',
                'is_primary' => false,
            ]));

        $this->childBusinessQuery(ProductBarcode::query(), $product, 'product_barcodes')
            ->where('product_id', $product->id)
            ->delete();

        $barcodes
            ->filter(fn (array $barcode) => !empty($barcode['barcode']))
            ->unique('barcode')
            ->values()
            ->each(function (array $barcode, int $index) use ($product) {
                ProductBarcode::create($this->childPayload('product_barcodes', $product, [
                    'product_id' => $product->id,
                    'barcode' => $barcode['barcode'],
                    'barcode_type' => $barcode['barcode_type'] ?? 'internal',
                    'type' => $barcode['barcode_type'] ?? 'internal',
                    'quantity' => 1,
                    'is_primary' => (bool) ($barcode['is_primary'] ?? $index === 0),
                    'status' => 'active',
                ]));
            });
    }

    private function syncPrices(Product $product, array $data): void
    {
        $prices = collect([
            ['price_type' => 'Retail', 'price' => $data['selling_price']],
            ['price_type' => 'Wholesale', 'price' => $data['wholesale_price'] ?? 0],
            ['price_type' => 'Dealer', 'price' => $data['dealer_price'] ?? 0],
            ['price_type' => 'Online', 'price' => $data['online_price'] ?? 0],
        ])->merge($data['prices'] ?? []);

        $this->childBusinessQuery(ProductPrice::withTrashed(), $product, 'product_prices')
            ->where('product_id', $product->id)
            ->forceDelete();

        $prices
            ->filter(fn (array $price) => !empty($price['price_type']))
            ->unique('price_type')
            ->each(fn (array $price) => ProductPrice::create($this->childPayload('product_prices', $product, [
                'product_id' => $product->id,
                'price_type' => $price['price_type'],
                'price' => $price['price'] ?? 0,
            ])));
    }

    private function syncImages(Product $product, array $images): void
    {
        $this->childBusinessQuery(ProductImage::withTrashed(), $product, 'product_images')
            ->where('product_id', $product->id)
            ->forceDelete();

        foreach ($images as $index => $image) {
            $path = is_array($image) ? ($image['image_path'] ?? null) : $image;

            if (!$path) {
                continue;
            }

            ProductImage::create($this->childPayload('product_images', $product, [
                'product_id' => $product->id,
                'image_path' => $path,
                'image_type' => is_array($image) ? ($image['image_type'] ?? 'gallery') : 'gallery',
                'sort_order' => is_array($image) ? ($image['sort_order'] ?? $index) : $index,
                'is_primary' => (bool) (is_array($image) ? ($image['is_primary'] ?? $index === 0) : $index === 0),
            ]));
        }
    }

    private function syncVariants(Product $product, array $variants): void
    {
        $this->childBusinessQuery(ProductVariant::withTrashed(), $product, 'product_variants')
            ->where('product_id', $product->id)
            ->forceDelete();

        foreach ($variants as $variant) {
            if (empty($variant['variant_name'])) {
                continue;
            }

            $productVariant = ProductVariant::create($this->childPayload('product_variants', $product, [
                'product_id' => $product->id,
                'variant_name' => $variant['variant_name'],
            ]));

            foreach (($variant['values'] ?? []) as $value) {
                if (!$value) {
                    continue;
                }

                ProductVariantValue::create([
                    'variant_id' => $productVariant->id,
                    'value' => $value,
                ]);
            }
        }
    }

    private function syncVariantItems(Product $product, array $items): void
    {
        $this->childBusinessQuery(ProductVariantItem::withTrashed(), $product, 'product_variant_items')
            ->where('product_id', $product->id)
            ->forceDelete();

        foreach ($items as $item) {
            if (empty($item['sku'])) {
                continue;
            }

            ProductVariantItem::create($this->childPayload('product_variant_items', $product, [
                'product_id' => $product->id,
                'sku' => $item['sku'],
                'barcode' => $item['barcode'] ?? null,
                'purchase_price' => $item['purchase_price'] ?? 0,
                'selling_price' => $item['selling_price'] ?? 0,
                'mrp' => $item['mrp'] ?? null,
                'current_stock' => $this->shouldStoreOpeningStockOnProduct() ? ($item['current_stock'] ?? 0) : 0,
            ]));
        }
    }

    private function syncBatches(Product $product, array $batches): void
    {
        $this->childBusinessQuery(ProductBatch::query(), $product, 'product_batches')
            ->where('product_id', $product->id)
            ->delete();

        foreach ($batches as $batch) {
            if (empty($batch['batch_no'])) {
                continue;
            }

            ProductBatch::create($this->childPayload('product_batches', $product, [
                'product_id' => $product->id,
                'batch_no' => $batch['batch_no'],
                'batch_number' => $batch['batch_no'],
                'manufacturing_date' => $batch['manufacturing_date'] ?? null,
                'expiry_date' => $batch['expiry_date'] ?? null,
                'purchase_price' => $batch['purchase_price'] ?? 0,
                'cost_price' => $batch['purchase_price'] ?? 0,
                'selling_price' => $batch['selling_price'] ?? 0,
                'quantity' => $batch['quantity'] ?? 0,
                'status' => 'active',
            ]));
        }
    }

    private function syncSerials(Product $product, array $serials): void
    {
        $this->childBusinessQuery(ProductSerial::withTrashed(), $product, 'product_serial_numbers')
            ->where('product_id', $product->id)
            ->forceDelete();

        foreach ($serials as $serial) {
            if (empty($serial['serial_number'])) {
                continue;
            }

            ProductSerial::create($this->childPayload('product_serial_numbers', $product, [
                'product_id' => $product->id,
                'serial_number' => $serial['serial_number'],
                'status' => $serial['status'] ?? 'available',
            ]));
        }
    }

    private function childBusinessQuery(Builder $query, Product $product, string $table): Builder
    {
        foreach (['business_id', 'tenant_id', 'company_id'] as $column) {
            if (Schema::hasColumn($table, $column) && isset($product->{$column})) {
                return $query->where($column, $product->{$column});
            }
        }

        return $query;
    }

    private function childPayload(string $table, Product $product, array $payload): array
    {
        foreach (['business_id', 'tenant_id', 'company_id'] as $column) {
            if (Schema::hasColumn($table, $column) && isset($product->{$column})) {
                $payload[$column] = $product->{$column};
                break;
            }
        }

        return array_filter(
            $payload,
            fn (string $column) => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function baseQuery(int $businessId): Builder
    {
        $query = Product::query();

        if (!Schema::hasColumn('products', 'deleted_at')) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query
            ->with($this->availableRelations())
            ->where($this->productBusinessColumn(), $businessId);
    }

    private function freshProduct(Product $product): Product
    {
        return $product->fresh($this->availableRelations());
    }

    private function uniqueSku(string $sku): string
    {
        $baseSku = $sku . '-COPY';
        $candidate = $baseSku;
        $counter = 1;
        $businessId = $this->businessId();

        while (
            Product::withTrashed()
                ->where('sku', $candidate)
                ->where($this->productBusinessColumn(), $businessId)
                ->exists()
        ) {
            $counter++;
            $candidate = $baseSku . '-' . $counter;
        }

        return $candidate;
    }

    private function shouldStoreOpeningStockOnProduct(): bool
    {
        return !Schema::hasTable('stock_ledgers') && !Schema::hasTable('opening_stock_entries');
    }

    private function businessId(): int
    {
        return AppController::businessId();
    }

    private function productBusinessColumn(): string
    {
        foreach (['business_id', 'tenant_id', 'company_id'] as $column) {
            if (Schema::hasColumn('products', $column)) {
                return $column;
            }
        }

        return 'id';
    }

    private function categoryOptions(?string $scope): array
    {
        $businessId = $this->businessId();
        $defaults = $scope === 'sub'
            ? ['Android Phones', 'Feature Phones', 'Chargers', 'Cables', 'Consumables', 'Spare Parts', 'Services', 'Other']
            : ['Electronics', 'Mobile Accessories', 'Grocery', 'Stationery', 'Hardware', 'Services', 'Other'];

        if (Schema::hasTable('product_categories')) {
            $nameColumn = Schema::hasColumn('product_categories', 'category_name') ? 'category_name' : 'name';
            $businessColumn = Schema::hasColumn('product_categories', 'business_id') ? 'business_id' : 'company_id';

            return DB::table('product_categories')
                ->where(function ($query) use ($businessId, $businessColumn) {
                    $query->whereNull($businessColumn)->orWhere($businessColumn, $businessId);
                })
                ->when(Schema::hasColumn('product_categories', 'parent_id'), function ($query) use ($scope) {
                    $scope === 'sub'
                        ? $query->whereNotNull('parent_id')
                        : $query->whereNull('parent_id');
                })
                ->when(Schema::hasColumn('product_categories', 'status'), fn ($query) => $query->where('status', 'active'))
                ->orderBy($nameColumn)
                ->get(['id', $nameColumn . ' as label'])
                ->map(fn ($row) => ['value' => (string) $row->label, 'label' => $row->label, 'id' => $row->id])
                ->values()
                ->all();
        }

        $column = $scope === 'sub' ? 'subcategory' : 'category';

        if (!Schema::hasColumn('products', $column)) {
            return collect($defaults)
                ->map(fn ($value) => ['value' => $value, 'label' => $value])
                ->all();
        }

        $options = DB::table('products')
            ->where($this->productBusinessColumn(), $businessId)
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn ($value) => ['value' => (string) $value, 'label' => (string) $value])
            ->values()
            ->all();

        if ($options) {
            return $options;
        }

        return collect($defaults)
            ->map(fn ($value) => ['value' => $value, 'label' => $value])
            ->all();
    }

    private function brandOptions(): array
    {
        $businessId = $this->businessId();
        $options = collect();

        if (Schema::hasTable('brands')) {
            $options = $options->merge(Brand::query()
                ->where(function (Builder $query) use ($businessId) {
                    $query->whereNull('business_id')->orWhere('business_id', $businessId);
                })
                ->when(Schema::hasColumn('brands', 'status'), fn (Builder $query) => $query->where('status', 'active'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Brand $brand) => [
                    'value' => (string) $brand->id,
                    'label' => $brand->name,
                    'id' => $brand->id,
                    'name' => $brand->name,
                ])
                ->values());
        }

        if (Schema::hasColumn('products', 'brand')) {
            $options = $options->merge(DB::table('products')
                ->where($this->productBusinessColumn(), $businessId)
                ->whereNotNull('brand')
                ->where('brand', '<>', '')
                ->distinct()
                ->orderBy('brand')
                ->pluck('brand')
                ->map(fn ($value) => [
                    'value' => (string) $value,
                    'label' => (string) $value,
                    'name' => (string) $value,
                ])
                ->values());
        }

        return $options
            ->filter(fn ($option) => !empty($option['label']))
            ->unique(fn ($option) => strtolower((string) $option['label']))
            ->sortBy('label')
            ->values()
            ->all();
    }

    private function availableRelations(): array
    {
        return array_values(array_filter([
            Schema::hasTable('product_barcodes') ? 'barcodes' : null,
            Schema::hasTable('product_prices') ? 'prices' : null,
            Schema::hasTable('product_images') ? 'images' : null,
            Schema::hasTable('product_variants') ? 'variants.values' : null,
            Schema::hasTable('product_variant_items') ? 'variantItems' : null,
            Schema::hasTable('product_batches') ? 'batches' : null,
            Schema::hasTable('product_serials') ? 'serials' : null,
        ]));
    }

    private function relationValues(Product $product, string $relation): Collection
    {
        if (!$product->relationLoaded($relation)) {
            return collect();
        }

        return $product->getRelation($relation) ?: collect();
    }
}
