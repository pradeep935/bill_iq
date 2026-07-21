<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariantItem;
use App\Models\StockLedger;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $sort = in_array($filters['sort'] ?? '', ['product_name', 'sku', 'quantity_available', 'average_cost', 'stock_value'], true)
            ? $filters['sort']
            : 'product_name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query = StockLedger::query()
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->leftJoin('branches', 'branches.id', '=', 'stock_ledgers.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'stock_ledgers.warehouse_id')
            ->leftJoin('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')
            ->where('stock_ledgers.business_id', $businessId)
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('stock_ledgers.branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('stock_ledgers.warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['product_id']), fn (Builder $q) => $q->where('stock_ledgers.product_id', $filters['product_id']))
            ->when(!empty($filters['category']), fn (Builder $q) => $q->where('products.category', $filters['category']))
            ->when(!empty($filters['brand']), fn (Builder $q) => $q->where('products.brand', $filters['brand']))
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
                'products.primary_barcode',
                'products.barcode',
                'products.reorder_stock',
                'products.reorder_level',
                'products.maximum_stock',
                'branches.name',
                'warehouses.name',
                'product_batches.batch_no',
                'product_batches.batch_number',
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
                COALESCE(products.primary_barcode, products.barcode) as barcode,
                products.reorder_stock,
                products.reorder_level,
                products.maximum_stock,
                branches.name as branch_name,
                warehouses.name as warehouse_name,
                COALESCE(product_batches.batch_no, product_batches.batch_number) as batch_no,
                product_batches.expiry_date,
                COALESCE(SUM(stock_ledgers.quantity_in), 0) - COALESCE(SUM(stock_ledgers.quantity_out), 0) as quantity_available,
                CASE
                    WHEN COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 0) = 0
                    THEN 0
                    ELSE COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in * stock_ledgers.unit_cost ELSE 0 END), 0)
                        / COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 1)
                END as average_cost
            ');

        $paginator = DB::query()
            ->fromSub($query, 'stock_summary')
            ->selectRaw('stock_summary.*, quantity_available * average_cost as stock_value')
            ->when(!empty($filters['stock_status']), function ($q) use ($filters) {
                $this->applyStockStatusFilter($q, $filters['stock_status']);
            })
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        $paginator->getCollection()->transform(function ($row) {
            $quantity = (float) $row->quantity_available;
            $reorder = (float) ($row->reorder_stock ?: $row->reorder_level ?: 0);
            $maximum = (float) ($row->maximum_stock ?: 0);

            $row->stock_status = $this->stockStatus($quantity, $reorder, $maximum);

            return $row;
        });

        return $paginator;
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

        return StockLedger::query()->create([
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
            ->where(function (Builder $query) use ($businessId) {
                $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->firstOrFail();
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
            $query->where('quantity_available', '<=', 0);
        } elseif ($status === 'low') {
            $query->where('quantity_available', '>', 0)
                ->whereRaw('quantity_available <= COALESCE(NULLIF(reorder_stock, 0), reorder_level, 0)');
        } elseif ($status === 'over') {
            $query->where('maximum_stock', '>', 0)
                ->whereRaw('quantity_available > maximum_stock');
        } elseif ($status === 'in') {
            $query->where('quantity_available', '>', 0)
                ->whereRaw('(maximum_stock IS NULL OR maximum_stock = 0 OR quantity_available <= maximum_stock)')
                ->whereRaw('quantity_available > COALESCE(NULLIF(reorder_stock, 0), reorder_level, 0)');
        }
    }

    private function stockStatus(float $quantity, float $reorder, float $maximum): string
    {
        if ($quantity <= 0) {
            return 'Out of Stock';
        }

        if ($maximum > 0 && $quantity > $maximum) {
            return 'Over Stock';
        }

        if ($reorder > 0 && $quantity <= $reorder) {
            return 'Low Stock';
        }

        return 'In Stock';
    }
}
