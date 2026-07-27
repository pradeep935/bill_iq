<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\OpeningStockVoucher;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariantItem;
use App\Models\Warehouse;
use App\Services\MasterDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OpeningStockService
{
    private StockService $stock;

    public function __construct(StockService $stock)
    {
        $this->stock = $stock;
    }

    public function list(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return OpeningStockVoucher::query()
            ->with(['branch', 'warehouse', 'items.product'])
            ->where('business_id', $businessId)
            ->when(!empty($filters['search']), fn (Builder $query) => $query->where('voucher_number', 'like', '%' . $filters['search'] . '%'))
            ->when(!empty($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(!empty($filters['branch_id']), fn (Builder $query) => $query->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $query) => $query->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['date_from']), fn (Builder $query) => $query->whereDate('opening_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $query) => $query->whereDate('opening_date', '<=', $filters['date_to']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): OpeningStockVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $status = $data['status'] ?? 'draft';
            $this->validateHeaderOwnership($businessId, $data);
            $this->validateNoDuplicateItems($data);

            $voucher = OpeningStockVoucher::query()->create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'voucher_number' => $this->nextVoucherNumber($businessId),
                'opening_date' => $data['opening_date'],
                'remarks' => $data['remarks'] ?? null,
                'status' => $status === 'posted' ? 'draft' : $status,
                'created_by' => Auth::id(),
            ]);

            $this->syncItems($voucher, $data['items'] ?? []);

            if ($status === 'posted') {
                $this->post($voucher);
            }

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Create',
                'business_id' => $businessId,
                'summary' => 'Opening stock voucher created',
            ]);

            return $voucher->fresh(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch']);
        });
    }

    public function update(OpeningStockVoucher $voucher, array $data): OpeningStockVoucher
    {
        return DB::transaction(function () use ($voucher, $data) {
            if ($voucher->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Only draft opening stock vouchers can be edited.',
                ]);
            }

            $businessId = AppController::businessId();
            $this->validateVoucher($voucher, $businessId);
            $this->validateHeaderOwnership($businessId, $data);
            $this->validateNoDuplicateItems($data);

            $voucher->update([
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'opening_date' => $data['opening_date'],
                'remarks' => $data['remarks'] ?? null,
                'updated_by' => Auth::id(),
            ]);

            $voucher->items()->delete();
            $this->syncItems($voucher, $data['items'] ?? []);

            if (($data['status'] ?? 'draft') === 'posted') {
                $this->post($voucher);
            }

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Update',
                'business_id' => $businessId,
                'summary' => 'Opening stock voucher updated',
            ]);

            return $voucher->fresh(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch']);
        });
    }

    public function post(OpeningStockVoucher $voucher): OpeningStockVoucher
    {
        return DB::transaction(function () use ($voucher) {
            $businessId = AppController::businessId();
            $this->validateVoucher($voucher, $businessId);

            $voucher = OpeningStockVoucher::query()
                ->where('business_id', $businessId)
                ->where('id', $voucher->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($voucher->status === 'posted') {
                throw ValidationException::withMessages([
                    'status' => 'Opening stock is already posted.',
                ]);
            }

            if ($this->hasLedgerPosting($voucher)) {
                throw ValidationException::withMessages([
                    'status' => 'Stock ledger already posted for this voucher.',
                ]);
            }

            if ($voucher->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => 'Cancelled opening stock cannot be posted.',
                ]);
            }

            $voucher->load('items.product');

            if (!$voucher->items->count()) {
                throw ValidationException::withMessages([
                    'items' => 'At least one opening stock item is required.',
                ]);
            }

            foreach ($voucher->items as $item) {
                $this->validateTrackingForItem($item->product, [
                    'batch_id' => $item->batch_id,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                ]);
                $this->validateItemForPosting($item);

                $batchId = $item->batch_id;

                if (!$batchId && $this->requiresBatch($item->product) && $item->batch_no) {
                    $batchId = ProductBatch::query()->create($this->batchPayload($voucher, $item))->id;

                    $item->update(['batch_id' => $batchId]);
                }

                $this->stock->increaseStock([
                    'business_id' => $voucher->business_id,
                    'branch_id' => $voucher->branch_id,
                    'warehouse_id' => $voucher->warehouse_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'batch_id' => $batchId,
                    'serial_id' => $item->serial_number_id,
                    'transaction_type' => 'opening_stock',
                    'reference_type' => OpeningStockVoucher::class,
                    'reference_id' => $voucher->id,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->purchase_cost,
                    'warehouse_location' => $item->warehouse_location,
                    'transaction_date' => $voucher->opening_date,
                    'remarks' => 'Opening stock ' . $voucher->voucher_number,
                ]);
            }

            $voucher->update([
                'status' => 'posted',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'posted_by' => Auth::id(),
                'posted_at' => now(),
            ]);

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Post',
                'business_id' => $businessId,
                'summary' => 'Opening stock posted to ledger',
            ]);

            return $voucher->fresh(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch']);
        });
    }

    public function deleteDraft(OpeningStockVoucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            $businessId = AppController::businessId();
            $this->validateVoucher($voucher, $businessId);

            $voucher = OpeningStockVoucher::query()
                ->where('business_id', $businessId)
                ->where('id', $voucher->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($voucher->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Posted or cancelled opening stock vouchers cannot be deleted.',
                ]);
            }

            if ($this->hasLedgerPosting($voucher)) {
                throw ValidationException::withMessages([
                    'status' => 'This draft cannot be deleted because it has stock ledger entries.',
                ]);
            }

            $voucher->items()->delete();
            $voucher->delete();

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Delete',
                'business_id' => $businessId,
                'summary' => 'Opening stock draft deleted',
            ]);
        });
    }

    public function reverse(OpeningStockVoucher $voucher, ?string $remarks = null): OpeningStockVoucher
    {
        return DB::transaction(function () use ($voucher, $remarks) {
            $businessId = AppController::businessId();
            $this->validateVoucher($voucher, $businessId);

            if ($voucher->status !== 'posted') {
                throw ValidationException::withMessages([
                    'status' => 'Only posted opening stock can be reversed.',
                ]);
            }

            $this->stock->reverseTransaction(OpeningStockVoucher::class, $voucher->id, $remarks ?: 'Opening stock reversal');

            $voucher->update([
                'status' => 'cancelled',
                'cancelled_by' => Auth::id(),
                'cancelled_at' => now(),
                'cancellation_reason' => $remarks ?: 'Opening stock cancelled',
            ]);

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Reverse',
                'business_id' => $businessId,
                'summary' => 'Opening stock reversed through stock ledger',
            ]);

            return $voucher->fresh(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch']);
        });
    }

    public function references(): array
    {
        return app(MasterDataService::class)->references(['branches', 'warehouses']);
    }

    public function searchProducts(string $search, array $scope = [])
    {
        $businessId = AppController::businessId();

        return Product::query()
            ->with(['barcodes', 'variantItems'])
            ->where(fn (Builder $query) => $this->scopeProductBusiness($query, $businessId))
            ->where('product_type', 'goods')
            ->where('item_type', 'stock')
            ->where('status', 'active')
            ->where(function (Builder $query) use ($search) {
                $query
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('sku', 'like', '%' . $search . '%')
                    ->orWhereHas('barcodes', fn (Builder $barcodeQuery) => $barcodeQuery->where('barcode', 'like', '%' . $search . '%'));

                foreach (['short_name', 'primary_barcode', 'barcode', 'extra_barcodes', 'hsn_code', 'hsn'] as $column) {
                    if (Schema::hasColumn('products', $column)) {
                        $query->orWhere($column, 'like', '%' . $search . '%');
                    }
                }
            })
            ->limit(20)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->primary_barcode ?: $product->barcode,
                'unit' => $product->unit ?: 'PCS',
                'tracking_type' => $product->tracking_type ?: 'none',
                'batch_required' => (bool) $product->batch_required,
                'expiry_required' => (bool) $product->expiry_required,
                'serial_required' => (bool) $product->serial_required,
                'cost_price' => (float) ($product->cost_price ?: 0),
                'selling_price' => (float) ($product->selling_price ?: $product->sale_price),
                'mrp' => $product->mrp !== null ? (float) $product->mrp : null,
                'current_stock' => $this->stock->getCurrentStock([
                    'business_id' => $businessId,
                    'branch_id' => $scope['branch_id'] ?? null,
                    'warehouse_id' => $scope['warehouse_id'] ?? null,
                    'product_id' => $product->id,
                ]),
                'variants' => $product->variantItems->map(fn (ProductVariantItem $variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'selling_price' => (float) $variant->selling_price,
                    'mrp' => $variant->mrp !== null ? (float) $variant->mrp : null,
                ])->values(),
            ]);
    }

    public function currentStockForItem(OpeningStockVoucher $voucher, $item): float
    {
        return $this->stock->getCurrentStock([
            'business_id' => $voucher->business_id,
            'branch_id' => $voucher->branch_id,
            'warehouse_id' => $voucher->warehouse_id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'batch_id' => $item->batch_id,
        ]);
    }

    private function syncItems(OpeningStockVoucher $voucher, array $items): void
    {
        foreach ($items as $item) {
            $product = $this->validProduct($voucher->business_id, (int) $item['product_id']);
            $variantId = $item['product_variant_id'] ?? null;
            $sellingPrice = (float) ($product->selling_price ?: $product->sale_price ?: 0);
            $mrp = $product->mrp !== null ? (float) $product->mrp : null;

            if ($variantId) {
                $variant = ProductVariantItem::query()
                    ->where('business_id', $voucher->business_id)
                    ->where('product_id', $product->id)
                    ->where('id', $variantId)
                    ->firstOrFail();
                $sellingPrice = (float) ($variant->selling_price ?: $sellingPrice);
                $mrp = $variant->mrp !== null ? (float) $variant->mrp : $mrp;
            }

            $this->validateQuantityForUnit((float) $item['quantity'], (string) ($product->unit ?: 'PCS'), $product->name);
            $stockValue = round((float) $item['quantity'] * (float) ($item['purchase_cost'] ?? 0), 2);

            $voucher->items()->create([
                'business_id' => $voucher->business_id,
                'branch_id' => $voucher->branch_id,
                'warehouse_id' => $voucher->warehouse_id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'batch_id' => $item['batch_id'] ?? null,
                'batch_no' => $item['batch_no'] ?? null,
                'serial_number_id' => $item['serial_number_id'] ?? $item['serial_id'] ?? null,
                'quantity' => $item['quantity'],
                'purchase_cost' => $item['purchase_cost'] ?? 0,
                'unit_cost' => $item['purchase_cost'] ?? 0,
                'stock_value' => $stockValue,
                'selling_price' => $sellingPrice,
                'mrp' => $mrp,
                'warehouse_location' => $item['warehouse_location'] ?? null,
                'manufacturing_date' => $item['manufacturing_date'] ?? null,
                'expiry_date' => $item['expiry_date'] ?? null,
                'remarks' => $item['remarks'] ?? null,
            ]);
        }
    }

    private function validProduct(int $businessId, int $productId): Product
    {
        $product = Product::query()
            ->where('id', $productId)
            ->where(fn (Builder $query) => $this->scopeProductBusiness($query, $businessId))
            ->firstOrFail();

        if ($product->product_type === 'service' || $product->item_type === 'non_stock') {
            throw ValidationException::withMessages([
                'product_id' => 'Services and non-stock products are not allowed in opening stock.',
            ]);
        }

        return $product;
    }

    private function validateHeaderOwnership(int $businessId, array $data): void
    {
        $branchId = $data['branch_id'] ?? null;

        if (empty($data['branch_id'])) {
            throw ValidationException::withMessages([
                'branch_id' => 'Please select a branch.',
            ]);
        }

        if (!empty($data['branch_id'])) {
            Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        }

        if (!empty($data['warehouse_id'])) {
            $warehouse = Warehouse::query()->where('business_id', $businessId)->where('id', $data['warehouse_id'])->firstOrFail();

            if (!empty($data['branch_id']) && (int) $warehouse->branch_id !== (int) $data['branch_id']) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'Selected warehouse must belong to the selected branch.',
                ]);
            }
        }

        if ($branchId) {
            $branchWarehouseCount = Warehouse::query()
                ->where('business_id', $businessId)
                ->where('branch_id', $branchId)
                ->where('status', 'active')
                ->count();

            if ($branchWarehouseCount > 0 && empty($data['warehouse_id'])) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'Please select a warehouse.',
                ]);
            }
        }
    }

    private function validateNoDuplicateItems(array $data): void
    {
        $seen = [];

        foreach (($data['items'] ?? []) as $index => $item) {
            $key = implode('|', [
                $data['branch_id'] ?? '',
                $data['warehouse_id'] ?? '',
                $item['product_id'] ?? '',
                $item['product_variant_id'] ?? '',
                $item['batch_id'] ?? '',
                strtoupper(trim((string) ($item['batch_no'] ?? ''))),
                strtoupper(trim((string) ($item['warehouse_location'] ?? ''))),
                $item['serial_number_id'] ?? $item['serial_id'] ?? '',
            ]);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "items.$index.product_id" => 'This product already exists in the voucher. Please update the existing quantity.',
                ]);
            }

            $seen[$key] = true;
        }
    }

    private function validateVoucher(OpeningStockVoucher $voucher, int $businessId): void
    {
        abort_unless((int) $voucher->business_id === $businessId, 404);
    }

    private function hasLedgerPosting(OpeningStockVoucher $voucher): bool
    {
        return DB::table('stock_ledgers')
            ->where('business_id', $voucher->business_id)
            ->where('reference_type', OpeningStockVoucher::class)
            ->where('reference_id', $voucher->id)
            ->where('transaction_type', 'opening_stock')
            ->exists();
    }

    private function nextVoucherNumber(int $businessId): string
    {
        $now = now();
        $financialYear = (int) $now->format('n') >= 4 ? $now->format('Y') : $now->copy()->subYear()->format('Y');
        $prefix = 'OS-' . $financialYear . '-';
        $last = OpeningStockVoucher::query()
            ->where('business_id', $businessId)
            ->where('voucher_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('voucher_number');

        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function requiresBatch(Product $product): bool
    {
        return (bool) $product->batch_required || in_array($product->tracking_type, ['batch', 'batch_expiry'], true);
    }

    private function requiresExpiry(Product $product): bool
    {
        return (bool) $product->expiry_required || $product->tracking_type === 'batch_expiry';
    }

    private function validateTrackingForItem(Product $product, array $item): void
    {
        if ($this->requiresBatch($product) && empty($item['batch_no']) && empty($item['batch_id'])) {
            throw ValidationException::withMessages([
                'items' => "Batch number is required for {$product->name}.",
            ]);
        }

        if ($this->requiresExpiry($product) && empty($item['expiry_date'])) {
            throw ValidationException::withMessages([
                'items' => "Expiry date is required for {$product->name}.",
            ]);
        }
    }

    private function validateItemForPosting($item): void
    {
        if ((float) $item->quantity <= 0) {
            throw ValidationException::withMessages([
                'items' => 'Every opening stock item quantity must be greater than zero.',
            ]);
        }

        if ((float) $item->purchase_cost <= 0 && !$this->allowsFreeOpeningStock($item->business_id)) {
            throw ValidationException::withMessages([
                'purchase_cost' => 'Cost price must be greater than zero.',
            ]);
        }

        if ($item->expiry_date && $item->manufacturing_date && $item->expiry_date->lte($item->manufacturing_date)) {
            throw ValidationException::withMessages([
                'items' => 'Expiry date must be greater than manufacturing date.',
            ]);
        }

        if ($item->expiry_date && $item->expiry_date->lt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'items' => 'Expired opening stock cannot be posted.',
            ]);
        }
    }

    private function batchPayload(OpeningStockVoucher $voucher, $item): array
    {
        $payload = [
            'product_id' => $item->product_id,
            'batch_no' => $item->batch_no,
            'expiry_date' => $item->expiry_date,
            'purchase_price' => $item->purchase_cost,
            'cost_price' => $item->purchase_cost,
            'selling_price' => $item->selling_price,
            'quantity' => 0,
            'status' => 'active',
        ];

        if (Schema::hasColumn('product_batches', 'business_id')) {
            $payload['business_id'] = $voucher->business_id;
        }

        if (Schema::hasColumn('product_batches', 'tenant_id')) {
            $payload['tenant_id'] = $voucher->business_id;
        }

        if (Schema::hasColumn('product_batches', 'batch_number')) {
            $payload['batch_number'] = $item->batch_no;
        }

        if (Schema::hasColumn('product_batches', 'manufacturing_date')) {
            $payload['manufacturing_date'] = $item->manufacturing_date;
        }

        if (Schema::hasColumn('product_batches', 'mfg_date')) {
            $payload['mfg_date'] = $item->manufacturing_date;
        }

        if (Schema::hasColumn('product_batches', 'mrp')) {
            $payload['mrp'] = $item->mrp;
        }

        if (Schema::hasColumn('product_batches', 'stock_qty')) {
            $payload['stock_qty'] = 0;
        }

        return $payload;
    }

    private function validateQuantityForUnit(float $quantity, string $unit, string $productName): void
    {
        $integerUnits = ['PCS', 'BOX', 'UNIT', 'NOS', 'SET', 'PAIR'];

        if (in_array(strtoupper($unit), $integerUnits, true) && floor($quantity) !== $quantity) {
            throw ValidationException::withMessages([
                'items' => "Decimal quantity is not allowed for {$productName}.",
            ]);
        }
    }

    private function allowsFreeOpeningStock(int $businessId): bool
    {
        if (config('inventory.allow_free_opening_stock', false)) {
            return true;
        }

        if (
            Schema::hasTable('business_inventory_settings') &&
            Schema::hasColumn('business_inventory_settings', 'allow_free_stock')
        ) {
            return (bool) DB::table('business_inventory_settings')
                ->where('business_id', $businessId)
                ->value('allow_free_stock');
        }

        return false;
    }

    private function scopeProductBusiness(Builder $query, int $businessId): void
    {
        if (Schema::hasColumn('products', 'business_id')) {
            $query->where('business_id', $businessId);
        }

        if (Schema::hasColumn('products', 'company_id')) {
            $method = Schema::hasColumn('products', 'business_id') ? 'orWhere' : 'where';
            $query->{$method}('company_id', $businessId);
        }
    }
}
