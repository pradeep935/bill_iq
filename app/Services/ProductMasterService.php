<?php

namespace App\Services;

use App\Http\Controllers\AppController;
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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
                    $inner
                        ->where('name', 'like', '%' . $search . '%')
                        ->orWhere('product_name', 'like', '%' . $search . '%')
                        ->orWhere('sku', 'like', '%' . $search . '%')
                        ->orWhere('primary_barcode', 'like', '%' . $search . '%')
                        ->orWhere('barcode', 'like', '%' . $search . '%')
                        ->orWhere('hsn_code', 'like', '%' . $search . '%')
                        ->orWhere('hsn', 'like', '%' . $search . '%')
                        ->orWhereHas('barcodes', function (Builder $barcodeQuery) use ($search) {
                            $barcodeQuery->where('barcode', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when(!empty($filters['category']), function (Builder $query) use ($filters) {
                $query->where(function (Builder $inner) use ($filters) {
                    $inner->where('category', $filters['category']);

                    if (is_numeric($filters['category'])) {
                        $inner->orWhere('category_id', (int) $filters['category']);
                    }
                });
            })
            ->when(!empty($filters['brand']), function (Builder $query) use ($filters) {
                $query->where(function (Builder $inner) use ($filters) {
                    $inner->where('brand', $filters['brand']);

                    if (is_numeric($filters['brand'])) {
                        $inner->orWhere('brand_id', (int) $filters['brand']);
                    }
                });
            })
            ->when(!empty($filters['unit']), function (Builder $query) use ($filters) {
                $query->where(function (Builder $inner) use ($filters) {
                    $inner->where('unit', $filters['unit']);

                    if (is_numeric($filters['unit'])) {
                        $inner->orWhere('unit_id', (int) $filters['unit']);
                    }
                });
            })
            ->when(!empty($filters['product_type']), fn (Builder $query) => $query->where('product_type', $filters['product_type']))
            ->when(!empty($filters['item_type']), fn (Builder $query) => $query->where('item_type', $filters['item_type']))
            ->when(isset($filters['gst_rate']) && $filters['gst_rate'] !== '', fn (Builder $query) => $query->where('gst_rate', $filters['gst_rate']))
            ->when(!empty($filters['status']), function (Builder $query) use ($filters) {
                if ($filters['status'] === 'deleted') {
                    $query->onlyTrashed();
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
            $product->created_by = Auth::id();
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
            $copy->created_by = Auth::id();
            $copy->updated_by = Auth::id();
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
        $product->delete();
    }

    public function restore(Product $product): Product
    {
        $product->restore();

        return $this->freshProduct($product);
    }

    public function forceDelete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $product->forceDelete();
        });
    }

    public function bulkStatus(array $ids, string $status): int
    {
        return $this->baseQuery($this->businessId())
            ->whereIn('id', $ids)
            ->update([
                'status' => $status,
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);
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
            'barcodes' => $product->barcodes->map(fn (ProductBarcode $barcode) => [
                'id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'barcode_type' => $barcode->barcode_type ?: $barcode->type,
                'is_primary' => (bool) $barcode->is_primary,
            ])->values(),
            'prices' => $product->prices->map(fn (ProductPrice $price) => [
                'id' => $price->id,
                'price_type' => $price->price_type,
                'price' => (float) $price->price,
            ])->values(),
            'images' => $product->images->map(fn (ProductImage $image) => [
                'id' => $image->id,
                'image_path' => $image->image_path,
                'image_type' => $image->image_type,
                'sort_order' => $image->sort_order,
                'is_primary' => (bool) $image->is_primary,
            ])->values(),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'variant_name' => $variant->variant_name,
                'values' => $variant->values->pluck('value')->values(),
            ])->values(),
            'variant_items' => $product->variantItems->map(fn (ProductVariantItem $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'purchase_price' => (float) $item->purchase_price,
                'selling_price' => (float) $item->selling_price,
                'mrp' => $item->mrp !== null ? (float) $item->mrp : null,
                'current_stock' => (float) $item->current_stock,
            ])->values(),
            'batches' => $product->batches->map(fn (ProductBatch $batch) => [
                'id' => $batch->id,
                'batch_no' => $batch->batch_no ?: $batch->batch_number,
                'manufacturing_date' => optional($batch->manufacturing_date)->format('Y-m-d'),
                'expiry_date' => optional($batch->expiry_date)->format('Y-m-d'),
                'purchase_price' => (float) ($batch->purchase_price ?: $batch->cost_price),
                'selling_price' => (float) ($batch->selling_price ?: 0),
                'quantity' => (float) ($batch->quantity ?: 0),
            ])->values(),
            'serials' => $product->serials->map(fn (ProductSerial $serial) => [
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

    private function fillProduct(Product $product, array $data): void
    {
        $businessId = $this->businessId();
        $name = $data['name'];
        $hsnId = $data['hsn_master_id'] ?? $data['hsn_id'] ?? null;
        $hsn = $hsnId ? HsnMaster::query()->find($hsnId) : null;
        $isService = $data['product_type'] === 'service';
        $itemType = $isService ? 'non_stock' : $data['item_type'];
        $trackingType = $isService ? 'none' : $data['tracking_type'];

        $product->business_id = $businessId;
        $product->company_id = $businessId;
        $product->name = $name;
        $product->product_name = $data['product_name'] ?? $name;
        $product->short_name = $data['short_name'] ?? null;
        $product->product_type = $data['product_type'];
        $product->item_type = $itemType;
        $product->category_id = $data['category_id'] ?? null;
        $product->sub_category_id = $data['sub_category_id'] ?? null;
        $product->brand_id = $data['brand_id'] ?? null;
        $product->unit_id = $data['unit_id'] ?? null;
        $product->hsn_id = $hsnId;
        $product->hsn_master_id = $hsnId;
        $product->category = $data['category'] ?? null;
        $product->subcategory = $data['subcategory'] ?? null;
        $product->brand = $data['brand'] ?? null;
        $product->variant = $data['variant'] ?? null;
        $product->unit = $data['unit'];
        $product->description = $data['description'] ?? null;
        $product->sku = $data['sku'];
        $product->barcode = $data['primary_barcode'] ?? null;
        $product->primary_barcode = $data['primary_barcode'] ?? null;
        $product->extra_barcodes = $data['extra_barcodes'] ?? null;
        $hsnCode = $hsn ? $hsn->hsn_code : $data['hsn_code'];
        $product->hsn = $hsnCode;
        $product->hsn_code = $hsnCode;
        $product->taxability = $data['taxability'];
        $product->gst_rate = $data['gst_rate'];
        $product->cess_rate = $data['cess_rate'] ?? 0;
        $product->reverse_charge = $data['reverse_charge'];
        $product->tax_inclusive = (bool) ($data['tax_inclusive'] ?? false);
        $product->invoice_description = $data['invoice_description'] ?? null;
        $product->purchase_price = $data['cost_price'] ?? 0;
        $product->default_purchase_price = $data['cost_price'] ?? 0;
        $product->cost_price = $data['cost_price'] ?? 0;
        $product->sale_price = $data['selling_price'];
        $product->default_selling_price = $data['selling_price'];
        $product->selling_price = $data['selling_price'];
        $product->mrp = $data['mrp'] ?? null;
        $product->wholesale_price = $data['wholesale_price'] ?? 0;
        $product->dealer_price = $data['dealer_price'] ?? 0;
        $product->online_price = $data['online_price'] ?? 0;
        $product->opening_stock = $this->shouldStoreOpeningStockOnProduct()
            ? ($isService ? 0 : ($data['opening_stock'] ?? 0))
            : ($product->exists ? $product->opening_stock : 0);
        $product->minimum_stock = $isService ? 0 : ($data['minimum_stock'] ?? 0);
        $product->reorder_stock = $isService ? 0 : ($data['reorder_stock'] ?? 0);
        $product->reorder_level = $isService ? 0 : ($data['reorder_stock'] ?? $data['minimum_stock'] ?? 0);
        $product->maximum_stock = $isService ? 0 : ($data['maximum_stock'] ?? 0);
        $product->tracking_type = $trackingType;
        $product->track_inventory = !$isService && $itemType === 'stock';
        $product->weight = $data['weight'] ?? null;
        $product->length = $data['length'] ?? null;
        $product->width = $data['width'] ?? null;
        $product->height = $data['height'] ?? null;
        $product->expiry_required = !$isService && (bool) ($data['expiry_required'] ?? false);
        $product->batch_required = !$isService && (bool) ($data['batch_required'] ?? false);
        $product->serial_required = !$isService && (bool) ($data['serial_required'] ?? false);
        $product->status = $data['status'];
        $product->updated_by = Auth::id();
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

        ProductBarcode::where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->delete();

        $barcodes
            ->filter(fn (array $barcode) => !empty($barcode['barcode']))
            ->unique('barcode')
            ->values()
            ->each(function (array $barcode, int $index) use ($product) {
                ProductBarcode::create([
                    'business_id' => $product->business_id,
                    'product_id' => $product->id,
                    'barcode' => $barcode['barcode'],
                    'barcode_type' => $barcode['barcode_type'] ?? 'internal',
                    'type' => $barcode['barcode_type'] ?? 'internal',
                    'quantity' => 1,
                    'is_primary' => (bool) ($barcode['is_primary'] ?? $index === 0),
                    'status' => 'active',
                ]);
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

        ProductPrice::withTrashed()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->forceDelete();

        $prices
            ->filter(fn (array $price) => !empty($price['price_type']))
            ->unique('price_type')
            ->each(fn (array $price) => ProductPrice::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'price_type' => $price['price_type'],
                'price' => $price['price'] ?? 0,
            ]));
    }

    private function syncImages(Product $product, array $images): void
    {
        ProductImage::withTrashed()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->forceDelete();

        foreach ($images as $index => $image) {
            $path = is_array($image) ? ($image['image_path'] ?? null) : $image;

            if (!$path) {
                continue;
            }

            ProductImage::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'image_path' => $path,
                'image_type' => is_array($image) ? ($image['image_type'] ?? 'gallery') : 'gallery',
                'sort_order' => is_array($image) ? ($image['sort_order'] ?? $index) : $index,
                'is_primary' => (bool) (is_array($image) ? ($image['is_primary'] ?? $index === 0) : $index === 0),
            ]);
        }
    }

    private function syncVariants(Product $product, array $variants): void
    {
        ProductVariant::withTrashed()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->forceDelete();

        foreach ($variants as $variant) {
            if (empty($variant['variant_name'])) {
                continue;
            }

            $productVariant = ProductVariant::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'variant_name' => $variant['variant_name'],
            ]);

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
        ProductVariantItem::withTrashed()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->forceDelete();

        foreach ($items as $item) {
            if (empty($item['sku'])) {
                continue;
            }

            ProductVariantItem::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'sku' => $item['sku'],
                'barcode' => $item['barcode'] ?? null,
                'purchase_price' => $item['purchase_price'] ?? 0,
                'selling_price' => $item['selling_price'] ?? 0,
                'mrp' => $item['mrp'] ?? null,
                'current_stock' => $this->shouldStoreOpeningStockOnProduct() ? ($item['current_stock'] ?? 0) : 0,
            ]);
        }
    }

    private function syncBatches(Product $product, array $batches): void
    {
        ProductBatch::where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->delete();

        foreach ($batches as $batch) {
            if (empty($batch['batch_no'])) {
                continue;
            }

            ProductBatch::create([
                'business_id' => $product->business_id,
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
            ]);
        }
    }

    private function syncSerials(Product $product, array $serials): void
    {
        ProductSerial::withTrashed()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->forceDelete();

        foreach ($serials as $serial) {
            if (empty($serial['serial_number'])) {
                continue;
            }

            ProductSerial::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'serial_number' => $serial['serial_number'],
                'status' => $serial['status'] ?? 'available',
            ]);
        }
    }

    private function baseQuery(int $businessId): Builder
    {
        return Product::with([
            'barcodes',
            'prices',
            'images',
            'variants.values',
            'variantItems',
            'batches',
            'serials',
        ])->where(function (Builder $query) use ($businessId) {
            $query
                ->where('business_id', $businessId)
                ->orWhere('company_id', $businessId);
        });
    }

    private function freshProduct(Product $product): Product
    {
        return $product->fresh([
            'barcodes',
            'prices',
            'images',
            'variants.values',
            'variantItems',
            'batches',
            'serials',
        ]);
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
                ->where(function (Builder $query) use ($businessId) {
                    $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
                })
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
}
