<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\BarcodeHistory;
use App\Models\BarcodeLabelPrint;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductSerialNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BarcodeCenterService
{
    public function references(): array
    {
        $businessId = AppController::businessId();
        return app(MasterDataService::class)->references(['categories', 'brands']) + [
            'products' => Product::query()->where(fn (Builder $q) => $this->scopeProduct($q, $businessId))->orderBy('name')->limit(300)->get($this->columns('products', ['id', 'name', 'sku', 'primary_barcode', 'barcode', 'selling_price', 'mrp'])),
            'formats' => ['CODE128', 'EAN-13', 'EAN-8', 'UPC-A', 'QR'],
            'types' => ['internal', 'manufacturer', 'supplier', 'variant', 'unit', 'batch', 'serial'],
            'templates' => ['50x25', '40x30', '38x25', 'a4', 'custom'],
        ];
    }

    public function dashboard(array $filters = []): array
    {
        $products = $this->baseProducts($filters)->get();
        $businessId = AppController::businessId();
        $alternate = ProductBarcode::query()->where('business_id', $businessId)->where('is_primary', false)->where(function (Builder $q) {
            $q->where('status', 'active')->orWhere('is_active', true);
        })->count();
        $generatedToday = BarcodeHistory::query()->where('business_id', $businessId)->where('event_type', 'generated')->whereDate('created_at', today())->count();
        $printedToday = BarcodeLabelPrint::query()->where('business_id', $businessId)->whereDate('created_at', today())->sum('labels_count');
        $duplicates = ProductBarcode::query()->where('business_id', $businessId)->where(function (Builder $q) {
            $q->where('status', 'active')->orWhere('is_active', true);
        })->select('barcode')->groupBy('barcode')->havingRaw('COUNT(*) > 1')->count();

        return [
            'with_barcode' => $products->filter(fn ($p) => $this->primaryBarcode($p) !== '')->count(),
            'without_barcode' => $products->filter(fn ($p) => $this->primaryBarcode($p) === '')->count(),
            'alternate_barcodes' => $alternate,
            'generated_today' => $generatedToday,
            'labels_printed_today' => (int) $printedToday,
            'issues' => $duplicates,
        ];
    }

    public function list(array $filters = [])
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);
        $paginator = $this->baseProducts($filters)->latest('products.updated_at')->paginate($perPage);
        $paginator->getCollection()->transform(fn (Product $product) => $this->presentProduct($product));
        return $paginator;
    }

    public function assign(array $data): ProductBarcode
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $product = $this->product((int) $data['product_id']);
            $barcode = trim((string) $data['barcode']);
            $this->validateFormat($barcode, $data['format'] ?? 'CODE128');
            $this->assertUnique($businessId, $barcode, $data['barcode_id'] ?? null);

            $row = !empty($data['barcode_id'])
                ? ProductBarcode::query()->where('business_id', $businessId)->where('id', $data['barcode_id'])->firstOrFail()
                : new ProductBarcode(['business_id' => $businessId, 'product_id' => $product->id, 'created_by' => Auth::id()]);

            $row->fill([
                'product_id' => $product->id,
                'product_variant_id' => $data['product_variant_id'] ?? null,
                'batch_id' => $data['batch_id'] ?? null,
                'serial_number_id' => $data['serial_number_id'] ?? null,
                'barcode' => $barcode,
                'format' => $data['format'] ?? 'CODE128',
                'barcode_type' => $data['barcode_type'] ?? $data['type'] ?? 'internal',
                'type' => $data['barcode_type'] ?? $data['type'] ?? 'internal',
                'quantity' => $data['quantity'] ?? 1,
                'is_primary' => (bool) ($data['is_primary'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'status' => !empty($data['is_active']) || !isset($data['is_active']) ? 'active' : 'inactive',
                'source' => $data['source'] ?? 'manual',
                'updated_by' => Auth::id(),
            ]);
            $row->save();

            if ($row->is_primary) {
                ProductBarcode::query()->where('business_id', $businessId)->where('product_id', $product->id)->where('id', '<>', $row->id)->update(['is_primary' => false]);
                $product->update(array_filter(['primary_barcode' => $barcode, 'barcode' => $barcode], fn ($v, $k) => Schema::hasColumn('products', $k), ARRAY_FILTER_USE_BOTH));
            }
            $this->history($product->id, $row->id, $barcode, empty($data['barcode_id']) ? 'assigned' : 'updated', $data);
            return $row->fresh(['product']);
        });
    }

    public function generate(array $data): ProductBarcode
    {
        $product = $this->product((int) $data['product_id']);
        $hasBarcode = $this->primaryBarcode($product) !== '';
        if ($hasBarcode && empty($data['overwrite'])) {
            throw ValidationException::withMessages(['overwrite' => 'Product already has a primary barcode. Confirm overwrite before generating.']);
        }
        $barcode = $this->nextBarcode($product->id, $data['format'] ?? 'CODE128');
        $row = $this->assign($data + ['barcode' => $barcode, 'format' => $data['format'] ?? 'CODE128', 'barcode_type' => 'internal', 'is_primary' => true, 'source' => 'generated']);
        $this->history($product->id, $row->id, $barcode, 'generated', $data);
        return $row;
    }

    public function bulkGenerate(array $data): array
    {
        return collect($data['product_ids'] ?? [])->map(fn ($id) => $this->generate(['product_id' => $id, 'format' => $data['format'] ?? 'CODE128', 'overwrite' => $data['overwrite'] ?? false]))->all();
    }

    public function setPrimary(int $barcodeId): ProductBarcode
    {
        return DB::transaction(function () use ($barcodeId) {
            $row = ProductBarcode::query()->where('business_id', AppController::businessId())->findOrFail($barcodeId);
            ProductBarcode::query()->where('business_id', AppController::businessId())->where('product_id', $row->product_id)->update(['is_primary' => false]);
            $row->update(['is_primary' => true, 'is_active' => true, 'status' => 'active', 'updated_by' => Auth::id()]);
            $this->product($row->product_id)->update(array_filter(['primary_barcode' => $row->barcode, 'barcode' => $row->barcode], fn ($v, $k) => Schema::hasColumn('products', $k), ARRAY_FILTER_USE_BOTH));
            $this->history($row->product_id, $row->id, $row->barcode, 'set_primary');
            return $row->fresh(['product']);
        });
    }

    public function toggle(int $barcodeId, bool $active): ProductBarcode
    {
        $row = ProductBarcode::query()->where('business_id', AppController::businessId())->findOrFail($barcodeId);
        $row->update(['is_active' => $active, 'status' => $active ? 'active' : 'inactive', 'updated_by' => Auth::id()]);
        $this->history($row->product_id, $row->id, $row->barcode, $active ? 'activated' : 'deactivated');
        return $row->fresh(['product']);
    }

    public function scan(string $barcode): ?array
    {
        $businessId = AppController::businessId();
        $barcode = trim($barcode);
        $row = ProductBarcode::query()->with(['product', 'variant', 'batch', 'serial'])->where('business_id', $businessId)->where('barcode', $barcode)->where(function (Builder $q) {
            $q->where('status', 'active')->orWhere('is_active', true);
        })->first();
        if ($row) {
            return ['type' => 'barcode', 'barcode' => $row, 'product' => $this->presentProduct($row->product)];
        }
        $product = Product::query()->where(fn (Builder $q) => $this->scopeProduct($q, $businessId))->where(fn (Builder $q) => $q->where('barcode', $barcode)->orWhere('primary_barcode', $barcode)->orWhere('sku', $barcode))->first();
        if ($product) {
            return ['type' => 'product', 'product' => $this->presentProduct($product->load('barcodes'))];
        }
        $serial = ProductSerialNumber::query()->with('product')->where('business_id', $businessId)->where(fn (Builder $q) => $q->where('serial_number', $barcode)->orWhere('imei_1', $barcode)->orWhere('imei_2', $barcode))->first();
        return $serial ? ['type' => 'serial', 'serial' => $serial, 'product' => $this->presentProduct($serial->product)] : null;
    }

    public function print(array $data): array
    {
        $product = $this->product((int) $data['product_id']);
        $barcode = $data['barcode'] ?? $this->primaryBarcode($product);
        BarcodeLabelPrint::query()->create(['business_id' => AppController::businessId(), 'product_id' => $product->id, 'product_barcode_id' => $data['product_barcode_id'] ?? null, 'barcode' => $barcode, 'template' => $data['template'] ?? '50x25', 'labels_count' => (int) ($data['labels_count'] ?? 1), 'settings' => $data, 'created_by' => Auth::id()]);
        return ['product' => $this->presentProduct($product), 'barcode' => $barcode, 'settings' => $data];
    }

    public function histories(array $filters = []): array
    {
        $businessId = AppController::businessId();
        return [
            'generation_history' => BarcodeHistory::query()->with(['product', 'creator'])->where('business_id', $businessId)->latest('id')->limit(500)->get(),
            'printing_history' => BarcodeLabelPrint::query()->with(['product', 'creator'])->where('business_id', $businessId)->latest('id')->limit(500)->get(),
        ];
    }

    public function reports(array $filters = []): array
    {
        $rows = $this->baseProducts($filters)->get()->map(fn (Product $p) => $this->presentProduct($p));
        return $this->histories($filters) + [
            'product_barcodes' => $rows,
            'without_barcode' => $rows->where('primary_barcode', '')->values(),
            'alternate_barcodes' => ProductBarcode::query()->with('product')->where('business_id', AppController::businessId())->where('is_primary', false)->get(),
        ];
    }

    private function baseProducts(array $filters = [])
    {
        $businessId = AppController::businessId();
        return Product::query()->with(['barcodes', 'category', 'brand'])
            ->where(fn (Builder $q) => $this->scopeProduct($q, $businessId))
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $s = '%' . $filters['search'] . '%';
                $q->where(fn (Builder $query) => $query->where('name', 'like', $s)->orWhere('sku', 'like', $s)->orWhere('barcode', 'like', $s)->orWhere('primary_barcode', 'like', $s)->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', 'like', $s)));
            })
            ->when(!empty($filters['product_id']), fn (Builder $q) => $q->where('id', $filters['product_id']))
            ->when(!empty($filters['category_id']), fn (Builder $q) => $q->where('category_id', $filters['category_id']))
            ->when(!empty($filters['brand_id']), fn (Builder $q) => $q->where('brand_id', $filters['brand_id']))
            ->when(($filters['has_barcode'] ?? '') === 'yes', fn (Builder $q) => $q->where(fn (Builder $x) => $x->whereNotNull('primary_barcode')->orWhereNotNull('barcode')->orWhereHas('barcodes')))
            ->when(($filters['has_barcode'] ?? '') === 'no', fn (Builder $q) => $q->whereNull('primary_barcode')->whereNull('barcode')->whereDoesntHave('barcodes'))
            ->when(!empty($filters['barcode_type']), fn (Builder $q) => $q->whereHas('barcodes', fn (Builder $b) => $b->where('barcode_type', $filters['barcode_type'])))
            ->when(!empty($filters['active_status']), fn (Builder $q) => $q->where('status', $filters['active_status']));
    }

    private function presentProduct(Product $product): array
    {
        $barcode = $this->primaryBarcode($product);
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'primary_barcode' => $barcode,
            'barcode_type' => optional($product->barcodes->firstWhere('barcode', $barcode))->barcode_type ?: 'primary',
            'alternate_barcodes' => $product->barcodes->where('is_primary', false)->pluck('barcode')->values(),
            'selling_price' => (float) ($product->selling_price ?: $product->default_selling_price ?: 0),
            'mrp' => (float) ($product->mrp ?: 0),
            'status' => $product->status,
            'updated_at' => optional($product->updated_at)->format('Y-m-d H:i'),
            'barcodes' => $product->barcodes->values(),
        ];
    }

    private function primaryBarcode(Product $product): string
    {
        $primary = $product->relationLoaded('barcodes') ? $product->barcodes->firstWhere('is_primary', true) : null;
        return (string) (optional($primary)->barcode ?: $product->primary_barcode ?: $product->barcode ?: '');
    }

    private function assertUnique(int $businessId, string $barcode, ?int $ignore = null): void
    {
        $exists = ProductBarcode::query()->where('business_id', $businessId)->where('barcode', $barcode)->where(fn (Builder $q) => $q->where('status', 'active')->orWhere('is_active', true))->when($ignore, fn (Builder $q) => $q->where('id', '<>', $ignore))->exists()
            || Product::query()->where(fn (Builder $q) => $this->scopeProduct($q, $businessId))->where(fn (Builder $q) => $q->where('barcode', $barcode)->orWhere('primary_barcode', $barcode))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['barcode' => 'Active barcode already exists in this business.']);
        }
    }

    private function validateFormat(string $barcode, string $format): void
    {
        if ($format === 'EAN-13' && !preg_match('/^\d{13}$/', $barcode)) throw ValidationException::withMessages(['barcode' => 'EAN-13 barcode must contain 13 digits.']);
        if ($format === 'EAN-8' && !preg_match('/^\d{8}$/', $barcode)) throw ValidationException::withMessages(['barcode' => 'EAN-8 barcode must contain 8 digits.']);
        if ($format === 'UPC-A' && !preg_match('/^\d{12}$/', $barcode)) throw ValidationException::withMessages(['barcode' => 'UPC-A barcode must contain 12 digits.']);
    }

    private function nextBarcode(int $productId, string $format): string
    {
        if ($format === 'EAN-13') return '20' . str_pad((string) $productId, 10, '0', STR_PAD_LEFT) . random_int(0, 9);
        if ($format === 'EAN-8') return '2' . str_pad((string) $productId, 6, '0', STR_PAD_LEFT) . random_int(0, 9);
        if ($format === 'UPC-A') return '4' . str_pad((string) $productId, 10, '0', STR_PAD_LEFT) . random_int(0, 9);
        return 'BIQ' . AppController::businessId() . str_pad((string) $productId, 6, '0', STR_PAD_LEFT) . now()->format('His');
    }

    private function product(int $id): Product
    {
        return Product::query()->with('barcodes')->where('id', $id)->where(fn (Builder $q) => $this->scopeProduct($q, AppController::businessId()))->firstOrFail();
    }

    private function history(int $productId, ?int $barcodeId, string $barcode, string $event, array $meta = []): void
    {
        BarcodeHistory::query()->create(['business_id' => AppController::businessId(), 'product_id' => $productId, 'product_barcode_id' => $barcodeId, 'barcode' => $barcode, 'event_type' => $event, 'meta' => $meta, 'created_by' => Auth::id()]);
    }

    private function scopeProduct(Builder $query, int $businessId): void
    {
        if (Schema::hasColumn('products', 'business_id')) $query->where('business_id', $businessId);
        if (Schema::hasColumn('products', 'company_id')) $query->{Schema::hasColumn('products', 'business_id') ? 'orWhere' : 'where'}('company_id', $businessId);
    }

    private function columns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
    }
}
