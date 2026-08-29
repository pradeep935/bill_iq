<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\DeliveryChallan;
use App\Models\GoodsReceipt;
use App\Models\LocationTransferVoucher;
use App\Models\OpeningStockVoucher;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariantItem;
use App\Models\ProductionOrder;
use App\Models\PurchaseReturnVoucher;
use App\Models\PurchaseVoucher;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use App\Models\StockAdjustmentVoucher;
use App\Models\StockLedger;
use App\Models\StockTransferVoucher;
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
        'production_consumption',
        'production_output',
        'manufacturing_consumption',
        'manufacturing_output',
        'manufacturing_wastage',
    ];

    public function getCurrentStock(array $scope): float
    {
        return $this->getConditionStock($scope, $scope['stock_status'] ?? 'saleable');
    }

    public function getConditionStock(array $scope, string $condition): float
    {
        return $this->quantityQuery(array_merge($scope, ['stock_status' => $condition]))->value('available_quantity') ?: 0.0;
    }

    public function getPhysicalStock(array $scope): float
    {
        $scopeWithoutCondition = $scope;
        unset($scopeWithoutCondition['stock_status']);

        return $this->quantityQuery($scopeWithoutCondition)->value('available_quantity') ?: 0.0;
    }

    public function getActiveReservedQuantity(array $scope, ?int $excludeReferenceId = null, bool $lock = false): float
    {
        $query = DB::table('stock_reservations')
            ->where('business_id', (int) ($scope['business_id'] ?? AppController::businessId()))
            ->where('status', 'active')
            ->where('product_id', $scope['product_id'])
            ->when(array_key_exists('branch_id', $scope), fn ($q) => $q->where('branch_id', $scope['branch_id']))
            ->when(array_key_exists('warehouse_id', $scope), fn ($q) => $q->where('warehouse_id', $scope['warehouse_id']))
            ->when(array_key_exists('product_variant_id', $scope), fn ($q) => $q->where('product_variant_id', $scope['product_variant_id']))
            ->when(array_key_exists('batch_id', $scope), fn ($q) => $q->where('batch_id', $scope['batch_id']))
            ->when($excludeReferenceId, fn ($q) => $q->where('reference_id', '!=', $excludeReferenceId));

        if ($lock) {
            $query->lockForUpdate();
        }

        return (float) $query
            ->selectRaw('COALESCE(SUM(reserved_quantity - fulfilled_quantity - released_quantity), 0) as quantity')
            ->value('quantity');
    }

    public function getAvailableToSell(array $scope, ?int $excludeReservationReferenceId = null, bool $lockReservations = false): float
    {
        return round(
            $this->getCurrentStock($scope) - $this->getActiveReservedQuantity($scope, $excludeReservationReferenceId, $lockReservations),
            3
        );
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

    public function validateAvailableToSell(array $scope, float $requiredQuantity, ?int $excludeReservationReferenceId = null, bool $lockReservations = false): void
    {
        $product = $this->productForScope($scope);

        if ((bool) ($product->allow_negative_stock ?? false)) {
            return;
        }

        $available = $this->getAvailableToSell($scope, $excludeReservationReferenceId, $lockReservations);

        if ($available < $requiredQuantity) {
            throw ValidationException::withMessages([
                'quantity' => 'Insufficient available-to-sell quantity after active reservations.',
            ]);
        }
    }

    public function getAverageCost(array $scope): float
    {
        $costScope = array_key_exists('stock_status', $scope) ? $scope : array_merge($scope, ['stock_status' => 'saleable']);
        $query = $this->baseLedgerQuery($costScope)
            ->where('quantity_in', '>', 0);

        $totalQty = (float) $query->sum('quantity_in');

        if ($totalQty <= 0) {
            return 0.0;
        }

        $totalCost = (float) $this->baseLedgerQuery($costScope)
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
                    ->selectRaw("product_id, SUBSTRING_INDEX(GROUP_CONCAT(image_path ORDER BY is_primary DESC, sort_order ASC, id DESC SEPARATOR '|'), '|', 1) as image_path")
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
            ->when(array_key_exists('product_variant_id', $filters) && $filters['product_variant_id'] !== '', fn (Builder $q) => $q->where('stock_ledgers.product_variant_id', $filters['product_variant_id']))
            ->when(array_key_exists('batch_id', $filters) && $filters['batch_id'] !== '', fn (Builder $q) => $q->where('stock_ledgers.batch_id', $filters['batch_id']))
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
                COALESCE(SUM(stock_ledgers.quantity_in), 0) - COALESCE(SUM(stock_ledgers.quantity_out), 0) as physical_quantity,
                COALESCE(SUM(CASE WHEN COALESCE(stock_ledgers.stock_status, "saleable") = "saleable" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) as saleable_quantity,
                COALESCE(SUM(CASE WHEN COALESCE(stock_ledgers.stock_status, "saleable") <> "saleable" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) as non_saleable_quantity,
                COALESCE(SUM(CASE WHEN stock_ledgers.stock_status = "damaged" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) as damaged_quantity,
                COALESCE(SUM(CASE WHEN stock_ledgers.stock_status = "expired" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) as expired_quantity,
                COALESCE(SUM(CASE WHEN stock_ledgers.stock_status = "defective" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) as defective_quantity,
                COALESCE(SUM(CASE WHEN stock_ledgers.stock_status = "quarantined" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) as quarantined_quantity,
                COALESCE(SUM(CASE WHEN stock_ledgers.stock_status = "lost" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) as lost_quantity,
                COALESCE(SUM(CASE WHEN COALESCE(stock_ledgers.stock_status, "saleable") = "saleable" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) as quantity_on_hand,
                COALESCE(MAX(reservations.reserved_quantity), 0) as reserved_quantity,
                COALESCE(SUM(CASE WHEN COALESCE(stock_ledgers.stock_status, "saleable") = "saleable" THEN stock_ledgers.quantity_in - stock_ledgers.quantity_out ELSE 0 END), 0) - COALESCE(MAX(reservations.reserved_quantity), 0) as quantity_available,
                CASE
                    WHEN COALESCE(SUM(CASE WHEN COALESCE(stock_ledgers.stock_status, "saleable") = "saleable" AND stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 0) = 0
                    THEN 0
                    ELSE COALESCE(SUM(CASE WHEN COALESCE(stock_ledgers.stock_status, "saleable") = "saleable" AND stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in * stock_ledgers.unit_cost ELSE 0 END), 0)
                        / COALESCE(SUM(CASE WHEN COALESCE(stock_ledgers.stock_status, "saleable") = "saleable" AND stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 1)
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
                SUM(physical_quantity) as physical_quantity,
                SUM(saleable_quantity) as saleable_quantity,
                SUM(non_saleable_quantity) as non_saleable_quantity,
                SUM(damaged_quantity) as damaged_quantity,
                SUM(expired_quantity) as expired_quantity,
                SUM(defective_quantity) as defective_quantity,
                SUM(quarantined_quantity) as quarantined_quantity,
                SUM(lost_quantity) as lost_quantity,
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
            'total_quantity' => round((float) $rows->sum('physical_quantity'), 3),
            'saleable_quantity' => round((float) $rows->sum('quantity_on_hand'), 3),
            'available_quantity' => round((float) $rows->sum('quantity_available'), 3),
            'non_saleable_quantity' => round((float) $rows->sum('non_saleable_quantity'), 3),
            'inventory_value' => round((float) $rows->sum('stock_value'), 2),
            'low_stock_products' => $rows->where('stock_status', 'Low Stock')->pluck('product_id')->unique()->count(),
            'out_of_stock_products' => $rows->where('stock_status', 'Out of Stock')->pluck('product_id')->unique()->count(),
        ];
    }

    public function productInventoryDetail(int $productId, array $filters = []): array
    {
        $businessId = AppController::businessId();
        $rows = $this->summary(array_merge($filters, ['product_id' => $productId, 'per_page' => 1000, 'view_mode' => 'detailed']))->getCollection();
        $product = Product::query()->with(['category', 'brand', 'images'])->where('id', $productId)->where(fn (Builder $query) => $this->scopeProductBusiness($query, $businessId))->firstOrFail();

        $ledger = StockLedger::query()
            ->with(['branch', 'warehouse', 'batch'])
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(array_key_exists('product_variant_id', $filters) && $filters['product_variant_id'] !== '', fn (Builder $q) => $q->where('product_variant_id', $filters['product_variant_id']))
            ->when(array_key_exists('batch_id', $filters) && $filters['batch_id'] !== '', fn (Builder $q) => $q->where('batch_id', $filters['batch_id']))
            ->latest('transaction_date')
            ->latest('id')
            ->limit(100)
            ->get();

        $recentMovements = $this->recentStockMovements($productId, $filters, 100);
        $lastAdjustment = $ledger->first(fn (StockLedger $entry) => $this->isAdjustmentTransaction($entry->transaction_type));

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
                'physical_quantity' => round((float) $group->sum('physical_quantity'), 3),
                'saleable_quantity' => round((float) $group->sum('saleable_quantity'), 3),
                'reserved_quantity' => round((float) $group->sum('reserved_quantity'), 3),
                'available_quantity' => round((float) $group->sum('quantity_available'), 3),
                'non_saleable_quantity' => round((float) $group->sum('non_saleable_quantity'), 3),
                'condition_stock' => $this->conditionBreakdownFromRows($group),
                'value' => round((float) $group->sum('stock_value'), 2),
            ])->values(),
            'warehouse_stock' => $rows->groupBy(fn ($row) => ($row->branch_id ?: 0) . '-' . ($row->warehouse_id ?: 0))->map(fn ($group) => [
                'branch' => $group->first()->branch_name ?: 'Default',
                'warehouse' => $group->first()->warehouse_name ?: 'Default',
                'quantity' => round((float) $group->sum('quantity_on_hand'), 3),
                'physical_quantity' => round((float) $group->sum('physical_quantity'), 3),
                'saleable_quantity' => round((float) $group->sum('saleable_quantity'), 3),
                'reserved_quantity' => round((float) $group->sum('reserved_quantity'), 3),
                'available_quantity' => round((float) $group->sum('quantity_available'), 3),
                'non_saleable_quantity' => round((float) $group->sum('non_saleable_quantity'), 3),
                'condition_stock' => $this->conditionBreakdownFromRows($group),
                'value' => round((float) $group->sum('stock_value'), 2),
            ])->values(),
            'batch_stock' => $rows->whereNotNull('batch_id')->map(fn ($row) => [
                'batch' => $row->batch_no,
                'expiry_date' => $row->expiry_date,
                'quantity' => (float) $row->quantity_on_hand,
                'physical_quantity' => (float) $row->physical_quantity,
                'saleable_quantity' => (float) $row->saleable_quantity,
                'reserved_quantity' => (float) $row->reserved_quantity,
                'available_quantity' => (float) $row->quantity_available,
                'non_saleable_quantity' => (float) $row->non_saleable_quantity,
                'condition_stock' => $this->conditionBreakdownFromRows(collect([$row])),
                'value' => round((float) $row->stock_value, 2),
            ])->values(),
            'serial_numbers' => Schema::hasTable('product_serial_numbers')
                ? DB::table('product_serial_numbers')->where('business_id', $businessId)->where('product_id', $productId)->get(['serial_number', 'status', 'batch_id'])->toArray()
                : [],
            'valuation' => [
                'quantity' => round((float) $rows->sum('quantity_on_hand'), 3),
                'physical_quantity' => round((float) $rows->sum('physical_quantity'), 3),
                'saleable_quantity' => round((float) $rows->sum('saleable_quantity'), 3),
                'non_saleable_quantity' => round((float) $rows->sum('non_saleable_quantity'), 3),
                'damaged_quantity' => round((float) $rows->sum('damaged_quantity'), 3),
                'reserved' => round((float) $rows->sum('reserved_quantity'), 3),
                'available' => round((float) $rows->sum('quantity_available'), 3),
                'value' => round((float) $rows->sum('stock_value'), 2),
            ],
            'condition_stock' => $this->conditionBreakdownFromRows($rows),
            'last_movement' => optional($ledger->first())->transaction_date?->toDateTimeString(),
            'last_purchase' => optional($ledger->firstWhere('transaction_type', 'purchase') ?: $ledger->firstWhere('transaction_type', 'goods_receipt'))->transaction_date?->toDateTimeString(),
            'last_sale' => optional($ledger->firstWhere('transaction_type', 'sale') ?: $ledger->firstWhere('transaction_type', 'delivery_challan'))->transaction_date?->toDateTimeString(),
            'last_adjustment' => optional($lastAdjustment)->transaction_date?->toDateTimeString(),
            'last_adjustment_reference' => $lastAdjustment ? ($this->stockReferenceNumbers(collect([$lastAdjustment]))[$lastAdjustment->reference_type . ':' . $lastAdjustment->reference_id] ?? (string) $lastAdjustment->reference_id) : null,
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
            'recent_movements' => $recentMovements,
        ];
    }

    private function conditionBreakdownFromRows($rows): array
    {
        return collect([
            'saleable' => $rows->sum('saleable_quantity'),
            'damaged' => $rows->sum('damaged_quantity'),
            'expired' => $rows->sum('expired_quantity'),
            'defective' => $rows->sum('defective_quantity'),
            'quarantined' => $rows->sum('quarantined_quantity'),
            'lost' => $rows->sum('lost_quantity'),
        ])->map(fn ($quantity, $condition) => [
            'condition' => $condition,
            'label' => str($condition)->replace('_', ' ')->title()->toString(),
            'quantity' => round((float) $quantity, 3),
        ])->filter(fn ($row) => abs($row['quantity']) > 0.0004)->values()->all();
    }

    private function recentStockMovements(int $productId, array $filters, int $limit = 100)
    {
        $businessId = AppController::businessId();
        $entries = StockLedger::query()
            ->with(['branch', 'warehouse'])
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(array_key_exists('product_variant_id', $filters) && $filters['product_variant_id'] !== '', fn (Builder $q) => $q->where('product_variant_id', $filters['product_variant_id']))
            ->when(array_key_exists('batch_id', $filters) && $filters['batch_id'] !== '', fn (Builder $q) => $q->where('batch_id', $filters['batch_id']))
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $references = $this->stockReferenceNumbers($entries);
        $runningSaleable = 0.0;
        $runningPhysical = 0.0;
        $rows = [];

        foreach ($entries as $entry) {
            $net = (float) $entry->quantity_in - (float) $entry->quantity_out;
            if (($entry->stock_status ?: 'saleable') === 'saleable') {
                $runningSaleable += $net;
            }
            $runningPhysical += $net;
            $referenceKey = $entry->reference_type . ':' . $entry->reference_id;

            $rows[] = [
                'id' => $entry->id,
                'date_time' => optional($entry->transaction_date)->toDateTimeString(),
                'transaction_type' => $entry->transaction_type,
                'transaction_label' => $this->stockTransactionLabel($entry->transaction_type),
                'reference_type' => class_basename((string) $entry->reference_type),
                'reference_id' => $entry->reference_id,
                'reference_number' => $references[$referenceKey] ?? (string) $entry->reference_id,
                'stock_status' => $entry->stock_status ?: 'saleable',
                'movement' => null,
                'stock_in' => (float) $entry->quantity_in,
                'stock_out' => (float) $entry->quantity_out,
                'running_balance' => round($runningSaleable, 3),
                'saleable_balance' => round($runningSaleable, 3),
                'physical_balance' => round($runningPhysical, 3),
                'branch' => optional($entry->branch)->name,
                'warehouse' => optional($entry->warehouse)->name,
            ];
        }

        return collect($this->mergeReclassificationMovements($rows))->reverse()->take($limit)->values();
    }

    private function mergeReclassificationMovements(array $rows): array
    {
        $merged = [];
        $skip = [];

        foreach ($rows as $index => $row) {
            if (isset($skip[$index])) {
                continue;
            }

            $pairIndex = null;
            if ($this->isAdjustmentTransaction($row['transaction_type']) && $row['reference_type'] === 'StockAdjustmentVoucher') {
                foreach ($rows as $candidateIndex => $candidate) {
                    if ($candidateIndex === $index || isset($skip[$candidateIndex])) {
                        continue;
                    }
                    if ($candidate['reference_type'] !== $row['reference_type'] || (int) $candidate['reference_id'] !== (int) $row['reference_id']) {
                        continue;
                    }
                    if ($this->isReclassificationPair($row, $candidate)) {
                        $pairIndex = $candidateIndex;
                        break;
                    }
                }
            }

            if ($pairIndex === null) {
                $merged[] = $row;
                continue;
            }

            $pair = $rows[$pairIndex];
            $out = ((float) $row['stock_out'] > 0) ? $row : $pair;
            $in = ((float) $row['stock_in'] > 0) ? $row : $pair;
            $condition = $in['stock_status'] === 'saleable' ? $out['stock_status'] : $in['stock_status'];
            $skip[$pairIndex] = true;

            $merged[] = [
                ...$row,
                'transaction_label' => $this->stockTransactionLabel($condition === 'damaged' ? 'damaged_stock' : 'stock_reclassification_out'),
                'movement' => 'Saleable -> ' . str($condition)->replace('_', ' ')->title()->toString(),
                'stock_in' => 0,
                'stock_out' => max((float) $out['stock_out'], (float) $in['stock_in']),
                'quantity' => max((float) $out['stock_out'], (float) $in['stock_in']),
                'running_balance' => $out['saleable_balance'],
                'saleable_balance' => $out['saleable_balance'],
                'physical_balance' => max($row['physical_balance'], $pair['physical_balance']),
            ];
        }

        return $merged;
    }

    public function stockReferenceNumbers($entries): array
    {
        $map = [
            OpeningStockVoucher::class => ['table' => 'opening_stock_vouchers', 'column' => 'voucher_number'],
            PurchaseVoucher::class => ['table' => 'purchase_vouchers', 'column' => 'voucher_number'],
            SalesVoucher::class => ['table' => 'sales_vouchers', 'column' => 'invoice_number', 'fallback' => 'voucher_number'],
            PurchaseReturnVoucher::class => ['table' => 'purchase_return_vouchers', 'column' => 'voucher_number'],
            SalesReturnVoucher::class => ['table' => 'sales_return_vouchers', 'column' => 'credit_note_number', 'fallback' => 'voucher_number'],
            StockAdjustmentVoucher::class => ['table' => 'stock_adjustment_vouchers', 'column' => 'voucher_number'],
            StockTransferVoucher::class => ['table' => 'stock_transfer_vouchers', 'column' => 'voucher_number'],
            LocationTransferVoucher::class => ['table' => 'location_transfer_vouchers', 'column' => 'voucher_number'],
            DeliveryChallan::class => ['table' => 'delivery_challans', 'column' => 'challan_number'],
            GoodsReceipt::class => ['table' => 'goods_receipts', 'column' => 'grn_number'],
            ProductionOrder::class => ['table' => 'production_orders', 'column' => 'order_number'],
        ];
        $references = [];

        foreach ($entries->groupBy('reference_type') as $referenceType => $group) {
            if (!isset($map[$referenceType])) {
                continue;
            }

            $meta = $map[$referenceType];
            $columns = ['id', $meta['column']];
            if (!empty($meta['fallback']) && $meta['fallback'] !== $meta['column']) {
                $columns[] = $meta['fallback'];
            }

            $rows = DB::table($meta['table'])
                ->whereIn('id', $group->pluck('reference_id')->filter()->unique()->values())
                ->get($columns);

            foreach ($rows as $row) {
                $number = $row->{$meta['column']} ?: ($row->{$meta['fallback'] ?? $meta['column']} ?? null);
                $references[$referenceType . ':' . $row->id] = $number ?: (string) $row->id;
            }
        }

        return $references;
    }

    private function stockTransactionLabel(string $type): string
    {
        return [
            'opening_stock' => 'Opening Stock',
            'opening_stock_reversal' => 'Opening Stock Reversal',
            'purchase' => 'Purchase',
            'sale' => 'Sale',
            'purchase_return' => 'Purchase Return',
            'sales_return' => 'Sales Return',
            'stock_adjustment_in' => 'Adjustment In',
            'stock_adjustment_out' => 'Adjustment Out',
            'stock_transfer_in' => 'Transfer In',
            'stock_transfer_out' => 'Transfer Out',
            'batch_transfer_in' => 'Batch Transfer In',
            'batch_transfer_out' => 'Batch Transfer Out',
            'stock_in_transit_in' => 'Stock In Transit In',
            'stock_in_transit_out' => 'Stock In Transit Out',
            'damaged_stock' => 'Damage',
            'expired_stock' => 'Expired Stock',
            'lost_stock' => 'Lost Stock',
            'theft_stock' => 'Theft Stock',
            'stock_reclassification_in' => 'Reclassification In',
            'stock_reclassification_out' => 'Reclassification Out',
            'location_transfer' => 'Location Transfer',
            'delivery_challan' => 'Delivery Challan',
            'goods_receipt' => 'Goods Receipt',
            'production_consumption' => 'Production Consumption',
            'production_output' => 'Production Output',
            'manufacturing_consumption' => 'Manufacturing Consumption',
            'manufacturing_output' => 'Manufacturing Output',
            'manufacturing_wastage' => 'Manufacturing Wastage',
        ][$type] ?? str($type)->replace('_', ' ')->title()->toString();
    }

    private function isAdjustmentTransaction(string $type): bool
    {
        return in_array($type, [
            'stock_adjustment_in',
            'stock_adjustment_out',
            'damaged_stock',
            'expired_stock',
            'lost_stock',
            'theft_stock',
            'stock_reclassification_in',
            'stock_reclassification_out',
        ], true);
    }

    private function isReclassificationPair(array $first, array $second): bool
    {
        $oneIn = ((float) $first['stock_in'] > 0 && (float) $second['stock_out'] > 0);
        $oneOut = ((float) $first['stock_out'] > 0 && (float) $second['stock_in'] > 0);

        if (!$oneIn && !$oneOut) {
            return false;
        }

        $quantityMatches = round(abs((float) $first['stock_in'] - (float) $second['stock_out']), 3) === 0.0
            || round(abs((float) $first['stock_out'] - (float) $second['stock_in']), 3) === 0.0;

        if (!$quantityMatches) {
            return false;
        }

        return in_array($first['transaction_type'], ['stock_reclassification_in', 'stock_reclassification_out', 'damaged_stock', 'expired_stock', 'lost_stock'], true)
            || in_array($second['transaction_type'], ['stock_reclassification_in', 'stock_reclassification_out', 'damaged_stock', 'expired_stock', 'lost_stock'], true);
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
            ->when(array_key_exists('batch_id', $scope), fn (Builder $q) => $q->where('batch_id', $scope['batch_id']))
            ->when(array_key_exists('stock_status', $scope), fn (Builder $q) => $q->where('stock_status', $scope['stock_status']));
    }

    private function applyStockStatusFilter($query, string $status): void
    {
        $conditionColumns = [
            'saleable' => 'quantity_on_hand',
            'damaged' => 'damaged_quantity',
            'expired' => 'expired_quantity',
            'defective' => 'defective_quantity',
            'quarantined' => 'quarantined_quantity',
            'lost' => 'lost_quantity',
        ];

        if ($status === 'non_saleable') {
            $query->where('non_saleable_quantity', '>', 0);
        } elseif (array_key_exists($status, $conditionColumns)) {
            $query->where($conditionColumns[$status], '>', 0);
        } elseif ($status === 'out') {
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
