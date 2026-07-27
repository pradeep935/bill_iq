<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariantItem;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Models\WarehouseProductStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StockService
{
    public const TYPES = [
        'opening_stock',
        'opening_stock_reversal',
        'purchase',
        'sale',
        'purchase_return',
        'sales_return',
        'stock_adjustment_in',
        'stock_adjustment_out',
        'stock_transfer_in',
        'stock_transfer_out',
        'batch_transfer_in',
        'batch_transfer_out',
        'stock_in_transit_in',
        'stock_in_transit_out',
        'damaged_stock',
        'expired_stock',
        'lost_stock',
        'theft_stock',
        'stock_reclassification_in',
        'stock_reclassification_out',
        'location_transfer',
        'delivery_challan',
        'goods_receipt',
    ];

    public function getCurrentStock(array $scope): float
    {
        return $this->quantityQuery($scope)->value('available_quantity') ?: 0.0;
    }

    public function getBranchStock(int $businessId, int $branchId, int $productId): float
    {
        return $this->getCurrentStock([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'product_id' => $productId,
        ]);
    }

    public function getWarehouseStock(int $businessId, ?int $branchId, int $warehouseId, int $productId): float
    {
        return $this->getCurrentStock([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
        ]);
    }

    public function getVariantStock(int $businessId, int $productId, int $variantId): float
    {
        return $this->getCurrentStock([
            'business_id' => $businessId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
        ]);
    }

    public function getBatchStock(int $businessId, int $productId, int $batchId): float
    {
        return $this->getCurrentStock([
            'business_id' => $businessId,
            'product_id' => $productId,
            'batch_id' => $batchId,
        ]);
    }

    public function increaseStock(array $data): StockLedger
    {
        return DB::transaction(function () use ($data) {
            $data['quantity_in'] = (float) ($data['quantity'] ?? $data['quantity_in'] ?? 0);
            $data['quantity_out'] = 0;

            return $this->createLedgerEntry($data);
        });
    }

    public function decreaseStock(array $data): StockLedger
    {
        return DB::transaction(function () use ($data) {
            $data['quantity_out'] = (float) ($data['quantity'] ?? $data['quantity_out'] ?? 0);
            $data['quantity_in'] = 0;

            $this->validateAvailableStock($data, $data['quantity_out']);

            return $this->createLedgerEntry($data);
        });
    }

    public function validateAvailableStock(array $scope, float $requiredQuantity): void
    {
        $product = $this->productForScope($scope);

        if ((bool) ($product->allow_negative_stock ?? false)) {
            return;
        }

        $available = $this->getCurrentStock($scope);

        if ($available < $requiredQuantity) {
            throw ValidationException::withMessages([
                'quantity' => 'Insufficient stock available.',
            ]);
        }
    }

    public function getAverageCost(array $scope): float
    {
        $query = $this->baseLedgerQuery($scope)
            ->where('quantity_in', '>', 0);

        $totalQty = (float) $query->sum('quantity_in');

        if ($totalQty <= 0) {
            return 0.0;
        }

        $totalCost = (float) $this->baseLedgerQuery($scope)
            ->where('quantity_in', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity_in * unit_cost), 0) as total_cost')
            ->value('total_cost');

        return round($totalCost / $totalQty, 2);
    }

    public function getStockValue(array $scope): float
    {
        return round($this->getCurrentStock($scope) * $this->getAverageCost($scope), 2);
    }

    public function reverseTransaction(string $referenceType, int $referenceId, ?string $remarks = null): int
    {
        return DB::transaction(function () use ($referenceType, $referenceId, $remarks) {
            $businessId = AppController::businessId();
            $entries = StockLedger::query()
                ->where('business_id', $businessId)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('transaction_type', '!=', 'opening_stock_reversal')
                ->get();

            $count = 0;

            foreach ($entries as $entry) {
                $reversalType = $entry->transaction_type === 'opening_stock'
                    ? 'opening_stock_reversal'
                    : $entry->transaction_type . '_reversal';

                $this->createLedgerEntry([
                    'business_id' => $entry->business_id,
                    'branch_id' => $entry->branch_id,
                    'warehouse_id' => $entry->warehouse_id,
                    'product_id' => $entry->product_id,
                    'product_variant_id' => $entry->product_variant_id,
                    'batch_id' => $entry->batch_id,
                    'transaction_type' => $reversalType,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'quantity_in' => (float) $entry->quantity_out,
                    'quantity_out' => (float) $entry->quantity_in,
                    'unit_cost' => (float) $entry->unit_cost,
                    'transaction_date' => now(),
                    'remarks' => $remarks ?: 'Reversal for ledger #' . $entry->id,
                ], false);

                $count++;
            }

            return $count;
        });
    }

    public function summary(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);
        $viewMode = ($filters['view_mode'] ?? 'summary') === 'detailed' ? 'detailed' : 'summary';
        $sort = in_array($filters['sort'] ?? '', ['product_name', 'sku', 'quantity_on_hand', 'quantity_available', 'average_cost', 'stock_value', 'last_updated'], true)
            ? $filters['sort']
            : 'product_name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->leftJoin('branches', 'branches.id', '=', 'stock_ledgers.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'stock_ledgers.warehouse_id')
            ->leftJoin('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoinSub(
                DB::table('stock_reservations')
                    ->selectRaw('business_id, branch_id, warehouse_id, product_id, product_variant_id, batch_id, COALESCE(SUM(reserved_quantity - fulfilled_quantity - released_quantity), 0) as reserved_quantity')
                    ->where('status', 'active')
                    ->groupBy('business_id', 'branch_id', 'warehouse_id', 'product_id', 'product_variant_id', 'batch_id'),
                'reservations',
                function ($join) {
                    $join->on('reservations.business_id', '=', 'stock_ledgers.business_id')
                        ->on('reservations.product_id', '=', 'stock_ledgers.product_id')
                        ->whereRaw('COALESCE(reservations.branch_id, 0) = COALESCE(stock_ledgers.branch_id, 0)')
                        ->whereRaw('COALESCE(reservations.warehouse_id, 0) = COALESCE(stock_ledgers.warehouse_id, 0)')
                        ->whereRaw('COALESCE(reservations.product_variant_id, 0) = COALESCE(stock_ledgers.product_variant_id, 0)')
                        ->whereRaw('COALESCE(reservations.batch_id, 0) = COALESCE(stock_ledgers.batch_id, 0)');
                }
            )
            ->leftJoinSub(
                DB::table('product_images')
                    ->selectRaw('product_id, MIN(image_path) as image_path')
                    ->whereNull('deleted_at')
                    ->groupBy('product_id'),
                'product_images',
                'product_images.product_id',
                '=',
                'products.id'
            )
            ->where('stock_ledgers.business_id', $businessId)
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $search = '%' . $filters['search'] . '%';
                $q->where(function (Builder $query) use ($search) {
                    $query->where('products.name', 'like', $search)
                        ->orWhere('products.sku', 'like', $search)
                        ->orWhere('products.primary_barcode', 'like', $search)
                        ->orWhere('products.barcode', 'like', $search)
                        ->orWhere('products.hsn', 'like', $search)
                        ->orWhere('products.hsn_code', 'like', $search)
                        ->orWhere('products.brand', 'like', $search)
                        ->orWhere('brands.name', 'like', $search);
                });
            })
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('stock_ledgers.branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('stock_ledgers.warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['product_id']), fn (Builder $q) => $q->where('stock_ledgers.product_id', $filters['product_id']))
            ->when(!empty($filters['category']), fn (Builder $q) => $q->where(fn (Builder $query) => $query->where('products.category_id', $filters['category'])->orWhere('products.category', $filters['category'])))
            ->when(!empty($filters['brand']), fn (Builder $q) => $q->where(fn (Builder $query) => $query->where('products.brand_id', $filters['brand'])->orWhere('products.brand', $filters['brand'])))
            ->when(!empty($filters['batch']), fn (Builder $q) => $q->where('product_batches.batch_no', 'like', '%' . $filters['batch'] . '%'))
            ->when(($filters['expiry_status'] ?? '') === 'expired', fn (Builder $q) => $q->whereDate('product_batches.expiry_date', '<', now()))
            ->when(($filters['expiry_status'] ?? '') === 'expiring', fn (Builder $q) => $q->whereBetween('product_batches.expiry_date', [now(), now()->addDays(30)]))
            ->groupBy([
                'stock_ledgers.business_id',
                'stock_ledgers.branch_id',
                'stock_ledgers.warehouse_id',
                'stock_ledgers.product_id',
                'stock_ledgers.product_variant_id',
                'stock_ledgers.batch_id',
                'products.name',
                'products.sku',
                'products.unit',
                'products.category',
                'products.brand',
                'products.hsn',
                'products.hsn_code',
                'products.primary_barcode',
                'products.barcode',
                'products.reorder_stock',
                'products.minimum_stock',
                'products.maximum_stock',
                'products.batch_required',
                'products.serial_required',
                'branches.name',
                'warehouses.name',
                'brands.name',
                'product_categories.name',
                'product_images.image_path',
                'product_batches.batch_no',
                'product_batches.expiry_date',
            ])
            ->selectRaw('
                stock_ledgers.business_id,
                stock_ledgers.branch_id,
                stock_ledgers.warehouse_id,
                stock_ledgers.product_id,
                stock_ledgers.product_variant_id,
                stock_ledgers.batch_id,
                products.name as product_name,
                products.sku,
                products.unit,
                products.category as category_text,
                products.brand as brand_text,
                COALESCE(product_categories.name, products.category) as category_name,
                COALESCE(brands.name, products.brand) as brand_name,
                COALESCE(products.primary_barcode, products.barcode) as barcode,
                COALESCE(products.hsn_code, products.hsn) as hsn,
                product_images.image_path,
                products.reorder_stock,
                products.minimum_stock as reorder_level,
                products.maximum_stock,
                products.batch_required,
                products.serial_required,
                branches.name as branch_name,
                warehouses.name as warehouse_name,
                product_batches.batch_no,
                product_batches.expiry_date,
                MAX(stock_ledgers.updated_at) as last_updated,
                MAX(stock_ledgers.created_by) as created_by_id,
                NULL as updated_by_id,
                COALESCE(SUM(stock_ledgers.quantity_in), 0) - COALESCE(SUM(stock_ledgers.quantity_out), 0) as quantity_on_hand,
                COALESCE(MAX(reservations.reserved_quantity), 0) as reserved_quantity,
                COALESCE(SUM(stock_ledgers.quantity_in), 0) - COALESCE(SUM(stock_ledgers.quantity_out), 0) - COALESCE(MAX(reservations.reserved_quantity), 0) as quantity_available,
                CASE
                    WHEN COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 0) = 0
                    THEN 0
                    ELSE COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in * stock_ledgers.unit_cost ELSE 0 END), 0)
                        / COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 1)
                END as average_cost
            ');

        $detailed = DB::query()
            ->fromSub($query, 'stock_summary')
            ->selectRaw('stock_summary.*, quantity_on_hand * average_cost as stock_value')
            ->when(!empty($filters['stock_status']), function ($q) use ($filters) {
                $this->applyStockStatusFilter($q, $filters['stock_status']);
            });

        $resultQuery = $viewMode === 'summary'
            ? $this->summaryModeQuery($detailed)
            : $detailed;

        $paginator = $resultQuery->orderBy($sort, $direction)->paginate($perPage);

        $paginator->getCollection()->transform(function ($row) {
            $quantity = (float) $row->quantity_available;
            $reorder = (float) ($row->reorder_stock ?: $row->reorder_level ?: 0);
            $maximum = (float) ($row->maximum_stock ?: 0);

            $row->stock_status = $this->stockStatus(
                (float) ($row->quantity_on_hand ?? 0),
                $quantity,
                (float) ($row->reserved_quantity ?? 0),
                $reorder,
                $maximum
            );

            return $row;
        });

        return $paginator;
    }

    private function summaryModeQuery($detailed)
    {
        return DB::query()
            ->fromSub($detailed, 'detailed_stock')
            ->selectRaw('
                business_id,
                NULL as branch_id,
                NULL as warehouse_id,
                product_id,
                NULL as product_variant_id,
                NULL as batch_id,
                product_name,
                sku,
                unit,
                category_text,
                brand_text,
                category_name,
                brand_name,
                barcode,
                hsn,
                image_path,
                reorder_stock,
                reorder_level,
                maximum_stock,
                batch_required,
                serial_required,
                NULL as branch_name,
                NULL as warehouse_name,
                NULL as batch_no,
                NULL as expiry_date,
                MAX(last_updated) as last_updated,
                MAX(created_by_id) as created_by_id,
                NULL as updated_by_id,
                SUM(quantity_on_hand) as quantity_on_hand,
                SUM(reserved_quantity) as reserved_quantity,
                SUM(quantity_available) as quantity_available,
                CASE WHEN SUM(quantity_on_hand) = 0 THEN 0 ELSE SUM(stock_value) / SUM(quantity_on_hand) END as average_cost,
                SUM(stock_value) as stock_value,
                COUNT(DISTINCT COALESCE(branch_id, 0)) as branch_count
            ')
            ->groupBy(
                'business_id',
                'product_id',
                'product_name',
                'sku',
                'unit',
                'category_text',
                'brand_text',
                'category_name',
                'brand_name',
                'barcode',
                'hsn',
                'image_path',
                'reorder_stock',
                'reorder_level',
                'maximum_stock'
                ,
                'batch_required',
                'serial_required'
            );
    }

    public function dashboard(array $filters = []): array
    {
        $rows = $this->summary(array_merge($filters, ['per_page' => 1000]))->getCollection();

        return [
            'total_products' => $rows->pluck('product_id')->unique()->count(),
            'total_quantity' => round((float) $rows->sum('quantity_on_hand'), 3),
            'inventory_value' => round((float) $rows->sum('stock_value'), 2),
            'low_stock_products' => $rows->where('stock_status', 'Low Stock')->pluck('product_id')->unique()->count(),
            'out_of_stock_products' => $rows->where('stock_status', 'Out of Stock')->pluck('product_id')->unique()->count(),
        ];
    }

    public function productInventoryDetail(int $productId, array $filters = []): array
    {
        $businessId = AppController::businessId();
        $rows = $this->summary(['product_id' => $productId, 'per_page' => 1000, 'view_mode' => 'detailed'])->getCollection();
        $product = Product::query()->with(['category', 'brand', 'images'])->where('id', $productId)->where(fn (Builder $query) => $this->scopeProductBusiness($query, $businessId))->firstOrFail();

        $ledger = StockLedger::query()
            ->with(['branch', 'warehouse', 'batch'])
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->latest('transaction_date')
            ->limit(100)
            ->get();

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->primary_barcode ?: $product->barcode,
                'category' => optional($product->category)->name ?: $product->category,
                'brand' => optional($product->brand)->name ?: $product->brand,
                'unit' => $product->unit ?: 'PCS',
                'image' => optional($product->images->sortByDesc('is_primary')->first())->image_path,
            ],
            'branch_stock' => $rows->groupBy('branch_id')->map(fn ($group) => [
                'branch_id' => $group->first()->branch_id,
                'branch' => $group->first()->branch_name ?: 'Default',
                'quantity' => round((float) $group->sum('quantity_on_hand'), 3),
                'value' => round((float) $group->sum('stock_value'), 2),
            ])->values(),
            'warehouse_stock' => $rows->groupBy(fn ($row) => ($row->branch_id ?: 0) . '-' . ($row->warehouse_id ?: 0))->map(fn ($group) => [
                'branch' => $group->first()->branch_name ?: 'Default',
                'warehouse' => $group->first()->warehouse_name ?: 'Default',
                'quantity' => round((float) $group->sum('quantity_on_hand'), 3),
                'value' => round((float) $group->sum('stock_value'), 2),
            ])->values(),
            'batch_stock' => $rows->whereNotNull('batch_id')->map(fn ($row) => [
                'batch' => $row->batch_no,
                'expiry_date' => $row->expiry_date,
                'quantity' => (float) $row->quantity_on_hand,
                'value' => round((float) $row->stock_value, 2),
            ])->values(),
            'serial_numbers' => Schema::hasTable('product_serial_numbers')
                ? DB::table('product_serial_numbers')->where('business_id', $businessId)->where('product_id', $productId)->get(['serial_number', 'status', 'batch_id'])->toArray()
                : [],
            'valuation' => [
                'quantity' => round((float) $rows->sum('quantity_on_hand'), 3),
                'reserved' => round((float) $rows->sum('reserved_quantity'), 3),
                'available' => round((float) $rows->sum('quantity_available'), 3),
                'value' => round((float) $rows->sum('stock_value'), 2),
            ],
            'last_movement' => optional($ledger->first())->transaction_date?->toDateTimeString(),
            'last_purchase' => optional($ledger->firstWhere('transaction_type', 'purchase') ?: $ledger->firstWhere('transaction_type', 'goods_receipt'))->transaction_date?->toDateTimeString(),
            'last_sale' => optional($ledger->firstWhere('transaction_type', 'sale') ?: $ledger->firstWhere('transaction_type', 'delivery_challan'))->transaction_date?->toDateTimeString(),
            'last_adjustment' => optional($ledger->firstWhere('transaction_type', 'stock_adjustment_in') ?: $ledger->firstWhere('transaction_type', 'stock_adjustment_out'))->transaction_date?->toDateTimeString(),
            'ledger' => $ledger->map(fn ($entry) => [
                'date' => optional($entry->transaction_date)->format('Y-m-d'),
                'type' => $entry->transaction_type,
                'branch' => optional($entry->branch)->name,
                'warehouse' => optional($entry->warehouse)->name,
                'batch' => optional($entry->batch)->batch_no,
                'in' => (float) $entry->quantity_in,
                'out' => (float) $entry->quantity_out,
                'unit_cost' => (float) $entry->unit_cost,
                'value' => (float) $entry->stock_value,
            ])->values(),
        ];
    }

    private function createLedgerEntry(array $data, bool $validate = true): StockLedger
    {
        $businessId = (int) ($data['business_id'] ?? AppController::businessId());
        $quantityIn = (float) ($data['quantity_in'] ?? 0);
        $quantityOut = (float) ($data['quantity_out'] ?? 0);

        $transactionType = $data['transaction_type'] ?? '';

        if (!in_array($transactionType, self::TYPES, true) && substr($transactionType, -9) !== '_reversal') {
            throw ValidationException::withMessages(['transaction_type' => 'Invalid stock transaction type.']);
        }

        if (($quantityIn > 0 && $quantityOut > 0) || ($quantityIn <= 0 && $quantityOut <= 0)) {
            throw ValidationException::withMessages(['quantity' => 'Enter either quantity in or quantity out.']);
        }

        if (empty($data['reference_type']) || empty($data['reference_id'])) {
            throw ValidationException::withMessages(['reference_type' => 'Stock reference is required.']);
        }

        if ($validate) {
            $this->validateOwnership($businessId, $data);
        }

        $ledger = StockLedger::query()->create([
            'business_id' => $businessId,
            'branch_id' => $data['branch_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'product_id' => $data['product_id'],
            'product_variant_id' => $data['product_variant_id'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
            'transaction_type' => $data['transaction_type'],
            'reference_type' => $data['reference_type'],
            'reference_id' => $data['reference_id'],
            'quantity_in' => $quantityIn,
            'quantity_out' => $quantityOut,
            'unit_cost' => $data['unit_cost'] ?? 0,
            'stock_value' => ($quantityIn ?: $quantityOut) * (float) ($data['unit_cost'] ?? 0),
            'serial_id' => $data['serial_id'] ?? null,
            'warehouse_location' => $data['warehouse_location'] ?? null,
            'stock_status' => $data['stock_status'] ?? 'saleable',
            'transaction_date' => $data['transaction_date'] ?? now(),
            'remarks' => $data['remarks'] ?? null,
            'created_by' => Auth::id(),
        ]);

        $this->refreshStockBalances($ledger);

        return $ledger;
    }

    public function refreshStockBalances(StockLedger $ledger): void
    {
        $businessId = (int) $ledger->business_id;
        $productId = (int) $ledger->product_id;
        $variantId = $ledger->product_variant_id ? (int) $ledger->product_variant_id : null;
        $batchId = $ledger->batch_id ? (int) $ledger->batch_id : null;
        $branchId = $ledger->branch_id ? (int) $ledger->branch_id : null;
        $warehouseId = $ledger->warehouse_id ? (int) $ledger->warehouse_id : null;

        if (Schema::hasColumn('products', 'current_stock')) {
            Product::query()
                ->where('id', $productId)
                ->update([
                    'current_stock' => $this->getCurrentStock([
                        'business_id' => $businessId,
                        'product_id' => $productId,
                    ]),
                ]);
        }

        if ($variantId && Schema::hasTable('product_variant_items') && Schema::hasColumn('product_variant_items', 'current_stock')) {
            ProductVariantItem::query()
                ->where('id', $variantId)
                ->where('product_id', $productId)
                ->update([
                    'current_stock' => $this->getCurrentStock([
                        'business_id' => $businessId,
                        'product_id' => $productId,
                        'product_variant_id' => $variantId,
                    ]),
                ]);
        }

        if (!Schema::hasTable('warehouse_product_stocks')) {
            return;
        }

        $scope = [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'batch_id' => $batchId,
        ];
        $quantity = $this->getCurrentStock($scope);
        $averageCost = $this->getAverageCost($scope);
        $payload = [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'warehouse_location_id' => null,
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'batch_id' => $batchId,
            'quantity_on_hand' => $quantity,
            'available_quantity' => $quantity,
            'average_cost' => $averageCost,
            'stock_value' => round($quantity * $averageCost, 2),
            'updated_at' => now(),
        ];

        $query = WarehouseProductStock::query()
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->whereNull('warehouse_location_id')
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId), fn (Builder $q) => $q->whereNull('branch_id'))
            ->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId), fn (Builder $q) => $q->whereNull('warehouse_id'))
            ->when($variantId, fn (Builder $q) => $q->where('product_variant_id', $variantId), fn (Builder $q) => $q->whereNull('product_variant_id'))
            ->when($batchId, fn (Builder $q) => $q->where('batch_id', $batchId), fn (Builder $q) => $q->whereNull('batch_id'));

        $stock = $query->first();

        if ($stock) {
            $stock->update($payload);
        } else {
            WarehouseProductStock::query()->create($payload + ['created_at' => now()]);
        }
    }

    private function validateOwnership(int $businessId, array $data): void
    {
        $this->productForScope(['business_id' => $businessId, 'product_id' => $data['product_id']]);

        if (!empty($data['branch_id'])) {
            Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        }

        if (!empty($data['warehouse_id'])) {
            Warehouse::query()->where('business_id', $businessId)->where('id', $data['warehouse_id'])->firstOrFail();
        }

        if (!empty($data['product_variant_id'])) {
            ProductVariantItem::query()
                ->where('business_id', $businessId)
                ->where('product_id', $data['product_id'])
                ->where('id', $data['product_variant_id'])
                ->firstOrFail();
        }

        if (!empty($data['batch_id'])) {
            ProductBatch::query()
                ->where('business_id', $businessId)
                ->where('product_id', $data['product_id'])
                ->where('id', $data['batch_id'])
                ->firstOrFail();
        }
    }

    private function productForScope(array $scope): Product
    {
        $businessId = (int) ($scope['business_id'] ?? AppController::businessId());

        return Product::query()
            ->where('id', $scope['product_id'])
            ->where(fn (Builder $query) => $this->scopeProductBusiness($query, $businessId))
            ->firstOrFail();
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

    private function quantityQuery(array $scope)
    {
        $query = $this->baseLedgerQuery($scope)
            ->selectRaw('COALESCE(SUM(quantity_in), 0) - COALESCE(SUM(quantity_out), 0) as available_quantity');

        return DB::query()->fromSub($query, 'stock_quantity');
    }

    private function baseLedgerQuery(array $scope): Builder
    {
        $businessId = (int) ($scope['business_id'] ?? AppController::businessId());

        return StockLedger::query()
            ->where('business_id', $businessId)
            ->when(array_key_exists('branch_id', $scope), fn (Builder $q) => $q->where('branch_id', $scope['branch_id']))
            ->when(array_key_exists('warehouse_id', $scope), fn (Builder $q) => $q->where('warehouse_id', $scope['warehouse_id']))
            ->when(!empty($scope['product_id']), fn (Builder $q) => $q->where('product_id', $scope['product_id']))
            ->when(array_key_exists('product_variant_id', $scope), fn (Builder $q) => $q->where('product_variant_id', $scope['product_variant_id']))
            ->when(array_key_exists('batch_id', $scope), fn (Builder $q) => $q->where('batch_id', $scope['batch_id']));
    }

    private function applyStockStatusFilter($query, string $status): void
    {
        if ($status === 'out') {
            $query->where('quantity_on_hand', '=', 0);
        } elseif ($status === 'low') {
            $query->where('quantity_available', '>', 0)
                ->whereRaw('quantity_available <= COALESCE(NULLIF(reorder_stock, 0), reorder_level, 0)');
        } elseif ($status === 'over') {
            $query->where('maximum_stock', '>', 0)
                ->whereRaw('quantity_available > maximum_stock');
        } elseif ($status === 'negative') {
            $query->where('quantity_on_hand', '<', 0);
        } elseif ($status === 'reserved') {
            $query->where('reserved_quantity', '>', 0);
        } elseif ($status === 'in') {
            $query->where('quantity_available', '>', 0)
                ->whereRaw('(maximum_stock IS NULL OR maximum_stock = 0 OR quantity_available <= maximum_stock)')
                ->whereRaw('quantity_available > COALESCE(NULLIF(reorder_stock, 0), reorder_level, 0)');
        }
    }

    private function stockStatus(float $current, float $available, float $reserved, float $reorder, float $maximum): string
    {
        if ($current < 0 || $available < 0) {
            return 'Negative Stock';
        }

        if ($current <= 0) {
            return 'Out of Stock';
        }

        if ($reserved > 0 && $available <= 0) {
            return 'Reserved';
        }

        if ($maximum > 0 && $available > $maximum) {
            return 'Over Stock';
        }

        if ($reorder > 0 && $available <= $reorder) {
            return 'Low Stock';
        }

        return 'In Stock';
    }
}
