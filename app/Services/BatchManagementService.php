<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\BatchHistory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BatchManagementService
{
    public function __construct(private StockService $stock)
    {
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        $masters = app(MasterDataService::class)->references(['branches', 'warehouses', 'categories', 'brands']);

        return array_merge($masters, [
            'products' => Product::query()
                ->where(fn (Builder $q) => $this->productBusinessScope($q, $businessId))
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(300)
                ->get($this->productColumns()),
            'statuses' => ['active', 'expire_today', 'near_expiry', 'expired', 'blocked', 'quarantined', 'empty'],
        ]);
    }

    public function dashboard(array $filters = []): array
    {
        $rows = $this->baseBatchRows($filters)->get();
        $today = now()->startOfDay();

        return [
            'active_batches' => $rows->where('batch_status', 'active')->count(),
            'near_expiry' => $rows->where('batch_status', 'near_expiry')->count(),
            'expired' => $rows->where('batch_status', 'expired')->count(),
            'total_batch_quantity' => round($rows->sum('quantity_on_hand'), 3),
            'total_batch_value' => round($rows->sum('batch_value'), 2),
            'blocked_batches' => $rows->where('batch_status', 'blocked')->count(),
            'quarantined_batches' => $rows->where('batch_status', 'quarantined')->count(),
            'expire_today' => $rows->filter(fn ($r) => $r->expiry_date && Carbon::parse($r->expiry_date)->isSameDay($today))->count(),
            'expire_7_days' => $rows->filter(fn ($r) => $r->expiry_date && Carbon::parse($r->expiry_date)->betweenIncluded($today, $today->copy()->addDays(7)))->count(),
            'expire_30_days' => $rows->filter(fn ($r) => $r->expiry_date && Carbon::parse($r->expiry_date)->betweenIncluded($today, $today->copy()->addDays(30)))->count(),
        ];
    }

    public function list(array $filters = [])
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);
        $rows = $this->baseBatchRows($filters);

        return DB::query()
            ->fromSub($rows, 'batch_rows')
            ->when(!empty($filters['batch_status']), fn ($q) => $q->where('batch_status', $filters['batch_status']))
            ->orderBy('expiry_date')
            ->orderBy('product_name')
            ->paginate($perPage);
    }

    public function detail(int $batchId, array $filters = []): array
    {
        $batch = $this->batchQuery()->with('product')->findOrFail($batchId);
        $businessId = AppController::businessId();
        $ledger = $this->ledgerQuery($batchId, $filters)->get();
        $opening = $ledger->where('transaction_type', 'opening_stock')->sum('quantity_in');
        $purchases = $ledger->whereIn('transaction_type', ['purchase', 'goods_receipt'])->sum('quantity_in');
        $purchaseReturns = $ledger->whereIn('transaction_type', ['purchase_return', 'purchase_return_out'])->sum('quantity_out');
        $sales = $ledger->whereIn('transaction_type', ['sale', 'delivery_challan'])->sum('quantity_out');
        $saleReturns = $ledger->whereIn('transaction_type', ['sale_return', 'sales_return_in'])->sum('quantity_in');
        $adjustedIn = $ledger->whereIn('transaction_type', ['stock_adjustment_in', 'stock_reclassification_in'])->sum('quantity_in');
        $adjustedOut = $ledger->whereIn('transaction_type', ['stock_adjustment_out', 'damaged_stock', 'expired_stock', 'stock_reclassification_out'])->sum('quantity_out');
        $transferredIn = $ledger->whereIn('transaction_type', ['stock_transfer_in', 'batch_transfer_in'])->sum('quantity_in');
        $transferredOut = $ledger->whereIn('transaction_type', ['stock_transfer_out', 'batch_transfer_out'])->sum('quantity_out');
        $produced = $ledger->whereIn('transaction_type', ['production_output', 'manufacturing_output'])->sum('quantity_in');
        $scope = ['business_id' => $businessId, 'product_id' => $batch->product_id, 'batch_id' => $batch->id];
        $history = BatchHistory::query()
            ->with(['creator:id,name', 'branch:id,name', 'warehouse:id,name'])
            ->where('business_id', $businessId)
            ->where('batch_id', $batch->id)
            ->latest('id')
            ->limit(80)
            ->get();

        return [
            'batch' => $this->presentBatch($batch),
            'summary' => [
                'opening_stock' => round($opening, 3),
                'purchases' => round($purchases, 3),
                'purchase_returns' => round($purchaseReturns, 3),
                'sales' => round($sales, 3),
                'sale_returns' => round($saleReturns, 3),
                'adjustments' => round($adjustedIn + $adjustedOut, 3),
                'adjusted_in' => round($adjustedIn, 3),
                'adjusted_out' => round($adjustedOut, 3),
                'transfers' => round($transferredIn + $transferredOut, 3),
                'transferred_in' => round($transferredIn, 3),
                'transferred_out' => round($transferredOut, 3),
                'produced_quantity' => round($produced, 3),
                'current_qty' => $this->stock->getCurrentStock($scope),
                'reserved_qty' => $this->reservedQty($scope),
                'available_qty' => $this->availableQty($scope),
                'average_cost' => $this->stock->getAverageCost($scope),
                'batch_value' => $this->stock->getStockValue($scope),
            ],
            'ledger' => $this->presentLedger($ledger),
            'history' => $history->map(fn (BatchHistory $event) => [
                'id' => $event->id,
                'date' => optional($event->created_at)->format('Y-m-d H:i'),
                'event_type' => $event->event_type,
                'quantity' => (float) $event->quantity,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'from_condition' => $event->from_condition,
                'to_condition' => $event->to_condition,
                'branch' => optional($event->branch)->name,
                'warehouse' => optional($event->warehouse)->name,
                'remarks' => $event->remarks,
                'user' => optional($event->creator)->name ?: '-',
            ])->values(),
        ];
    }

    public function ledger(int $batchId, array $filters = []): array
    {
        return $this->presentLedger($this->ledgerQuery($batchId, $filters)->get());
    }

    public function fefo(array $filters): ?array
    {
        if (empty($filters['product_id'])) {
            return null;
        }

        $row = $this->baseBatchRows($filters)
            ->whereNotNull('expiry_date')
            ->where('quantity_available', '>', 0)
            ->whereNotIn('raw_status', ['blocked', 'quarantined'])
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->orderBy('expiry_date')
            ->first();

        return $row ? (array) $row : null;
    }

    public function updateStatus(int $batchId, string $status, ?string $reason = null, ?string $releaseOutcome = null): ProductBatch
    {
        if (!in_array($status, ['active', 'blocked', 'quarantined'], true)) {
            throw ValidationException::withMessages(['status' => 'Unsupported batch status.']);
        }

        return DB::transaction(function () use ($batchId, $status, $reason, $releaseOutcome) {
            $batch = $this->batchQuery()->lockForUpdate()->findOrFail($batchId);
            $fromStatus = $batch->status;
            $fromCondition = $batch->condition_status ?? null;
            $payload = ['status' => $status, 'updated_by' => Auth::id()];

            if ($status === 'blocked') {
                $payload += ['blocked_reason' => $reason ?: 'Blocked manually', 'blocked_by' => Auth::id(), 'blocked_at' => now()];
            }

            if ($status === 'quarantined') {
                $payload += [
                    'blocked_reason' => $reason ?: 'Moved to quarantine',
                    'condition_status' => 'quarantined',
                    'quarantined_by' => Auth::id(),
                    'quarantined_at' => now(),
                ];
            }

            if ($status === 'active') {
                if ($batch->expiry_date && Carbon::parse($batch->expiry_date)->lt(now()->startOfDay())) {
                    throw ValidationException::withMessages(['status' => 'Expired batch cannot be made active.']);
                }

                $outcome = $releaseOutcome ?: 'saleable';
                if (!in_array($outcome, ['saleable', 'damaged', 'expired', 'blocked', 'return_to_supplier'], true)) {
                    throw ValidationException::withMessages(['release_outcome' => 'Please select a valid release outcome.']);
                }
                $targetStatus = $outcome === 'blocked' ? 'blocked' : ($outcome === 'expired' ? 'expired' : 'active');

                $payload += [
                    'status' => $targetStatus,
                    'unblocked_by' => Auth::id(),
                    'unblocked_at' => now(),
                    'released_by' => Auth::id(),
                    'released_at' => now(),
                    'release_outcome' => $outcome,
                    'condition_status' => $outcome,
                    'blocked_reason' => $targetStatus === 'blocked' ? ($reason ?: 'Released as blocked') : null,
                ];
            }

            $batch->update($payload);
            $this->history($batch->fresh(), $status === 'active' ? 'released' : $status, [
                'from_status' => $fromStatus,
                'to_status' => $payload['status'] ?? $status,
                'from_condition' => $fromCondition,
                'to_condition' => $payload['condition_status'] ?? $fromCondition,
                'remarks' => $reason ?: ($releaseOutcome ? 'Release outcome: ' . $releaseOutcome : null),
            ]);

            return $batch->fresh('product');
        });
    }

    public function transfer(int $batchId, array $data): array
    {
        return DB::transaction(function () use ($batchId, $data) {
            $batch = $this->batchQuery()->lockForUpdate()->findOrFail($batchId);
            $quantity = (float) ($data['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Transfer quantity must be greater than zero.']);
            }

            if (
                (int) ($data['source_branch_id'] ?? 0) === (int) ($data['destination_branch_id'] ?? 0)
                && (int) ($data['source_warehouse_id'] ?? 0) === (int) ($data['destination_warehouse_id'] ?? 0)
                && (string) ($data['source_location'] ?? '') === (string) ($data['destination_location'] ?? '')
            ) {
                throw ValidationException::withMessages(['destination_warehouse_id' => 'Destination must differ by branch, warehouse or location.']);
            }

            if (in_array($batch->status, ['blocked', 'quarantined'], true) && empty($data['allow_restricted'])) {
                throw ValidationException::withMessages(['batch_id' => 'Blocked or quarantined batches cannot be transferred without approval.']);
            }

            if ($batch->expiry_date && Carbon::parse($batch->expiry_date)->lt(now()->startOfDay())) {
                throw ValidationException::withMessages(['batch_id' => 'Expired batch cannot be transferred.']);
            }

            $sourceScope = [
                'business_id' => AppController::businessId(),
                'product_id' => $batch->product_id,
                'batch_id' => $batch->id,
                'branch_id' => $data['source_branch_id'] ?? null,
                'warehouse_id' => $data['source_warehouse_id'] ?? null,
            ];
            if ($this->availableQty($sourceScope) < $quantity) {
                throw ValidationException::withMessages(['quantity' => 'Transfer quantity exceeds available batch quantity.']);
            }

            $base = [
                'business_id' => AppController::businessId(),
                'product_id' => $batch->product_id,
                'batch_id' => $batch->id,
                'quantity' => $quantity,
                'unit_cost' => $this->stock->getAverageCost($sourceScope),
                'reference_type' => ProductBatch::class,
                'reference_id' => $batch->id,
                'transaction_date' => now(),
                'remarks' => $data['remarks'] ?? 'Batch transfer',
            ];

            $this->stock->decreaseStock($base + [
                'branch_id' => $data['source_branch_id'] ?? null,
                'warehouse_id' => $data['source_warehouse_id'] ?? null,
                'warehouse_location' => $data['source_location'] ?? null,
                'transaction_type' => 'batch_transfer_out',
            ]);
            $this->stock->increaseStock($base + [
                'branch_id' => $data['destination_branch_id'] ?? null,
                'warehouse_id' => $data['destination_warehouse_id'] ?? null,
                'warehouse_location' => $data['destination_location'] ?? null,
                'transaction_type' => 'batch_transfer_in',
            ]);

            $this->history($batch, 'transferred', [
                'quantity' => $quantity,
                'branch_id' => $data['destination_branch_id'] ?? null,
                'warehouse_id' => $data['destination_warehouse_id'] ?? null,
                'voucher_type' => ProductBatch::class,
                'voucher_id' => $batch->id,
                'remarks' => $data['remarks'] ?? 'Batch transfer',
            ]);

            return $this->detail($batch->id);
        });
    }

    public function split(int $batchId, array $data): ProductBatch
    {
        return DB::transaction(function () use ($batchId, $data) {
            $source = $this->batchQuery()->lockForUpdate()->findOrFail($batchId);
            $quantity = (float) ($data['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Split quantity must be greater than zero.']);
            }

            $available = $this->stock->getCurrentStock(['business_id' => AppController::businessId(), 'product_id' => $source->product_id, 'batch_id' => $source->id]);
            if ($quantity > $available) {
                throw ValidationException::withMessages(['quantity' => 'Split quantity cannot exceed available batch quantity.']);
            }

            $target = ProductBatch::query()->create($this->batchPayload($source, $data['batch_number'], $source->status ?: 'active'));
            $this->moveBetweenBatches($source, $target, $quantity, 'Batch split');
            $this->history($source, 'split_source', ['quantity' => $quantity, 'voucher_type' => ProductBatch::class, 'voucher_id' => $target->id, 'remarks' => 'Split into ' . $data['batch_number']]);
            $this->history($target, 'split_created', ['quantity' => $quantity, 'voucher_type' => ProductBatch::class, 'voucher_id' => $source->id, 'remarks' => 'Split from ' . ($source->batch_no ?: $source->batch_number)]);

            return $target->fresh('product');
        });
    }

    public function merge(int $sourceBatchId, int $targetBatchId): ProductBatch
    {
        return DB::transaction(function () use ($sourceBatchId, $targetBatchId) {
            if ($sourceBatchId === $targetBatchId) {
                throw ValidationException::withMessages(['target_batch_id' => 'Source and target batch cannot be same.']);
            }

            $source = $this->batchQuery()->lockForUpdate()->findOrFail($sourceBatchId);
            $target = $this->batchQuery()->lockForUpdate()->findOrFail($targetBatchId);

            foreach (['product_id', 'expiry_date', 'manufacturing_date'] as $field) {
                if ((string) ($source->{$field} ?? '') !== (string) ($target->{$field} ?? '')) {
                    throw ValidationException::withMessages(['target_batch_id' => 'Only identical product, cost, MFG and expiry batches can be merged.']);
                }
            }

            if (round((float) ($source->cost_price ?: $source->purchase_price), 2) !== round((float) ($target->cost_price ?: $target->purchase_price), 2)) {
                throw ValidationException::withMessages(['target_batch_id' => 'Only identical product, cost, MFG and expiry batches can be merged.']);
            }

            if (($source->condition_status ?? 'saleable') !== ($target->condition_status ?? 'saleable')) {
                throw ValidationException::withMessages(['target_batch_id' => 'Only batches with the same condition can be merged.']);
            }

            $quantity = $this->stock->getCurrentStock(['business_id' => AppController::businessId(), 'product_id' => $source->product_id, 'batch_id' => $source->id]);
            if ($quantity > 0) {
                $this->moveBetweenBatches($source, $target, $quantity, 'Batch merge');
            }

            $source->update(['status' => 'merged', 'updated_by' => Auth::id()]);
            $this->history($source, 'merged_source', ['quantity' => $quantity, 'to_status' => 'merged', 'voucher_type' => ProductBatch::class, 'voucher_id' => $target->id, 'remarks' => 'Merged into ' . ($target->batch_no ?: $target->batch_number)]);
            $this->history($target, 'merged_target', ['quantity' => $quantity, 'voucher_type' => ProductBatch::class, 'voucher_id' => $source->id, 'remarks' => 'Merged from ' . ($source->batch_no ?: $source->batch_number)]);

            return $target->fresh('product');
        });
    }

    public function reports(array $filters = []): array
    {
        $rows = $this->baseBatchRows($filters)->get();

        return [
            'batch_stock' => $rows,
            'expiry_report' => $rows->whereNotNull('expiry_date')->values(),
            'expire_today_report' => $rows->where('batch_status', 'expire_today')->values(),
            'near_expiry_report' => $rows->where('batch_status', 'near_expiry')->values(),
            'expired_report' => $rows->where('batch_status', 'expired')->values(),
            'blocked_report' => $rows->where('batch_status', 'blocked')->values(),
            'quarantine_report' => $rows->where('batch_status', 'quarantined')->values(),
            'fefo_priority' => $rows->whereNotNull('fefo_priority')->sortBy('fefo_priority')->values(),
            'batch_movement' => $rows->sortByDesc('last_movement')->values(),
            'batch_valuation' => $rows->map(fn ($r) => ['batch_number' => $r->batch_number, 'product_name' => $r->product_name, 'quantity' => $r->quantity_on_hand, 'average_cost' => $r->average_cost, 'batch_value' => $r->batch_value])->values(),
        ];
    }

    private function baseBatchRows(array $filters = [])
    {
        $businessId = AppController::businessId();
        $batchNo = $this->batchNoExpression();
        $mfg = $this->mfgExpression();

        $query = DB::table('product_batches')
            ->join('products', 'products.id', '=', 'product_batches.product_id')
            ->leftJoin('stock_ledgers', function ($join) use ($businessId) {
                $join->on('stock_ledgers.batch_id', '=', 'product_batches.id')->where('stock_ledgers.business_id', '=', $businessId);
            })
            ->leftJoin('branches', 'branches.id', '=', 'stock_ledgers.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'stock_ledgers.warehouse_id')
            ->leftJoinSub($this->reservationSubQuery(), 'reservations', function ($join) {
                $join->on('reservations.business_id', '=', 'stock_ledgers.business_id')
                    ->on('reservations.product_id', '=', 'stock_ledgers.product_id')
                    ->whereRaw('COALESCE(reservations.branch_id, 0) = COALESCE(stock_ledgers.branch_id, 0)')
                    ->whereRaw('COALESCE(reservations.warehouse_id, 0) = COALESCE(stock_ledgers.warehouse_id, 0)')
                    ->whereRaw('COALESCE(reservations.batch_id, 0) = COALESCE(stock_ledgers.batch_id, 0)');
            })
            ->where(fn ($q) => $this->batchBusinessScope($q, $businessId))
            ->where(fn ($q) => $this->productBusinessScope($q, $businessId))
            ->when(!empty($filters['search']), function ($q) use ($filters, $batchNo) {
                $search = '%' . $filters['search'] . '%';
                $q->where(function ($query) use ($search, $batchNo) {
                    $query->whereRaw("{$batchNo} like ?", [$search])
                        ->orWhere('products.name', 'like', $search)
                        ->orWhere('products.sku', 'like', $search)
                        ->orWhere('products.barcode', 'like', $search)
                        ->orWhere('products.primary_barcode', 'like', $search);
                });
            })
            ->when(!empty($filters['product_id']), fn ($q) => $q->where('product_batches.product_id', $filters['product_id']))
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('stock_ledgers.branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn ($q) => $q->where('stock_ledgers.warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['mfg_from']), fn ($q) => $q->whereDate(DB::raw($mfg), '>=', $filters['mfg_from']))
            ->when(!empty($filters['mfg_to']), fn ($q) => $q->whereDate(DB::raw($mfg), '<=', $filters['mfg_to']))
            ->when(!empty($filters['expiry_from']), fn ($q) => $q->whereDate('product_batches.expiry_date', '>=', $filters['expiry_from']))
            ->when(!empty($filters['expiry_to']), fn ($q) => $q->whereDate('product_batches.expiry_date', '<=', $filters['expiry_to']))
            ->when(($filters['expiry_filter'] ?? '') === 'near', fn ($q) => $q->whereBetween('product_batches.expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()]))
            ->when(($filters['expiry_filter'] ?? '') === 'expired', fn ($q) => $q->whereDate('product_batches.expiry_date', '<', now()->toDateString()))
            ->groupBy('product_batches.id', 'product_batches.product_id', 'product_batches.status', 'product_batches.expiry_date', DB::raw($batchNo), DB::raw($mfg), 'product_batches.lot_number', 'product_batches.condition_status', 'product_batches.source_voucher_type', 'product_batches.source_voucher_id', 'products.name', 'products.sku', 'products.primary_barcode', 'products.barcode', 'branches.name', 'warehouses.name', 'stock_ledgers.branch_id', 'stock_ledgers.warehouse_id')
            ->selectRaw("
                product_batches.id,
                product_batches.product_id,
                stock_ledgers.branch_id,
                stock_ledgers.warehouse_id,
                {$batchNo} as batch_number,
                product_batches.lot_number,
                product_batches.condition_status,
                product_batches.source_voucher_type,
                product_batches.source_voucher_id,
                products.name as product_name,
                products.sku,
                COALESCE(products.primary_barcode, products.barcode) as barcode,
                branches.name as branch_name,
                warehouses.name as warehouse_name,
                {$mfg} as mfg_date,
                product_batches.expiry_date,
                DATEDIFF(product_batches.expiry_date, CURRENT_DATE) as days_remaining,
                COALESCE(SUM(stock_ledgers.quantity_in), 0) - COALESCE(SUM(stock_ledgers.quantity_out), 0) as quantity_on_hand,
                COALESCE(MAX(reservations.reserved_quantity), 0) as reserved_quantity,
                COALESCE(SUM(stock_ledgers.quantity_in), 0) - COALESCE(SUM(stock_ledgers.quantity_out), 0) - COALESCE(MAX(reservations.reserved_quantity), 0) as quantity_available,
                CASE WHEN COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 0) = 0 THEN 0 ELSE COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in * stock_ledgers.unit_cost ELSE 0 END), 0) / COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 1) END as average_cost,
                MAX(stock_ledgers.transaction_date) as last_movement,
                product_batches.status as raw_status,
                CASE
                    WHEN product_batches.status IN ('blocked', 'quarantined') THEN NULL
                    WHEN product_batches.expiry_date IS NULL THEN NULL
                    ELSE ROW_NUMBER() OVER (PARTITION BY product_batches.product_id ORDER BY product_batches.expiry_date ASC, {$mfg} ASC, product_batches.id ASC)
                END as fefo_priority
            ");

        return DB::query()
            ->fromSub($query, 'batch_base')
            ->selectRaw("
                batch_base.*,
                quantity_on_hand * average_cost as batch_value,
                CASE
                    WHEN raw_status IN ('blocked', 'quarantined') THEN 0
                    WHEN expiry_date IS NOT NULL AND expiry_date < CURRENT_DATE THEN 0
                    ELSE quantity_available
                END as saleable_quantity_available,
                CASE
                    WHEN raw_status = 'blocked' THEN 'blocked'
                    WHEN raw_status = 'quarantined' THEN 'quarantined'
                    WHEN quantity_on_hand <= 0 THEN 'empty'
                    WHEN expiry_date IS NOT NULL AND expiry_date < CURRENT_DATE THEN 'expired'
                    WHEN expiry_date IS NOT NULL AND expiry_date = CURRENT_DATE THEN 'expire_today'
                    WHEN expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN 'near_expiry'
                    ELSE 'active'
                END as batch_status
            ");
    }

    private function ledgerQuery(int $batchId, array $filters = [])
    {
        return StockLedger::query()
            ->with(['product', 'branch', 'warehouse', 'creator'])
            ->where('business_id', AppController::businessId())
            ->where('batch_id', $batchId)
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->orderBy('transaction_date')
            ->orderBy('id');
    }

    private function presentLedger($ledger): array
    {
        $balance = 0;

        return $ledger->map(function (StockLedger $row) use (&$balance) {
            $balance += (float) $row->quantity_in - (float) $row->quantity_out;
            return [
                'id' => $row->id,
                'date' => optional($row->transaction_date)->format('Y-m-d H:i'),
                'voucher' => class_basename($row->reference_type) . '#' . $row->reference_id,
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id,
                'type' => $row->transaction_type,
                'in' => (float) $row->quantity_in,
                'out' => (float) $row->quantity_out,
                'balance' => round($balance, 3),
                'cost' => (float) $row->unit_cost,
                'stock_value' => round(((float) $row->quantity_in - (float) $row->quantity_out) * (float) $row->unit_cost, 2),
                'branch' => optional($row->branch)->name,
                'warehouse' => optional($row->warehouse)->name,
                'location' => $row->warehouse_location,
                'condition' => $row->stock_status,
                'remarks' => $row->remarks,
                'user' => optional($row->creator)->name ?: '-',
            ];
        })->values()->all();
    }

    private function presentBatch(ProductBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'batch_number' => $batch->batch_no ?: $batch->batch_number,
            'lot_number' => $batch->lot_number,
            'product_id' => $batch->product_id,
            'product' => optional($batch->product)->name,
            'sku' => optional($batch->product)->sku,
            'barcode' => optional($batch->product)->primary_barcode ?: optional($batch->product)->barcode,
            'mfg_date' => optional($batch->manufacturing_date ?: $batch->mfg_date)->format('Y-m-d'),
            'expiry_date' => optional($batch->expiry_date)->format('Y-m-d'),
            'status' => $batch->status,
            'condition_status' => $batch->condition_status,
            'source_voucher_type' => $batch->source_voucher_type,
            'source_voucher_id' => $batch->source_voucher_id,
            'blocked_reason' => $batch->blocked_reason,
        ];
    }

    private function history(ProductBatch $batch, string $eventType, array $data = []): void
    {
        BatchHistory::query()->create([
            'business_id' => AppController::businessId(),
            'batch_id' => $batch->id,
            'product_id' => $batch->product_id,
            'branch_id' => $data['branch_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'event_type' => $eventType,
            'voucher_type' => $data['voucher_type'] ?? null,
            'voucher_id' => $data['voucher_id'] ?? null,
            'quantity' => $data['quantity'] ?? 0,
            'from_status' => $data['from_status'] ?? null,
            'to_status' => $data['to_status'] ?? $batch->status,
            'from_condition' => $data['from_condition'] ?? null,
            'to_condition' => $data['to_condition'] ?? ($batch->condition_status ?? null),
            'remarks' => $data['remarks'] ?? null,
            'created_by' => Auth::id(),
        ]);
    }

    private function moveBetweenBatches(ProductBatch $source, ProductBatch $target, float $quantity, string $remarks): void
    {
        $businessId = AppController::businessId();
        $scopes = StockLedger::query()
            ->where('business_id', $businessId)
            ->where('product_id', $source->product_id)
            ->where('batch_id', $source->id)
            ->selectRaw('branch_id, warehouse_id, product_variant_id, SUM(quantity_in - quantity_out) as qty, CASE WHEN SUM(CASE WHEN quantity_in > 0 THEN quantity_in ELSE 0 END) = 0 THEN 0 ELSE SUM(CASE WHEN quantity_in > 0 THEN quantity_in * unit_cost ELSE 0 END) / SUM(CASE WHEN quantity_in > 0 THEN quantity_in ELSE 0 END) END as cost')
            ->groupBy('branch_id', 'warehouse_id', 'product_variant_id')
            ->havingRaw('qty > 0')
            ->get();

        $remaining = $quantity;
        foreach ($scopes as $scope) {
            if ($remaining <= 0) {
                break;
            }
            $move = min($remaining, (float) $scope->qty);
            $base = [
                'business_id' => $businessId,
                'branch_id' => $scope->branch_id,
                'warehouse_id' => $scope->warehouse_id,
                'product_id' => $source->product_id,
                'product_variant_id' => $scope->product_variant_id,
                'quantity' => $move,
                'unit_cost' => (float) $scope->cost,
                'reference_type' => ProductBatch::class,
                'reference_id' => $source->id,
                'transaction_date' => now(),
                'remarks' => $remarks,
            ];

            $this->stock->decreaseStock($base + ['batch_id' => $source->id, 'transaction_type' => 'stock_reclassification_out']);
            $this->stock->increaseStock($base + ['batch_id' => $target->id, 'transaction_type' => 'stock_reclassification_in']);
            $remaining -= $move;
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages(['quantity' => 'Unable to split or merge requested batch quantity.']);
        }
    }

    private function batchPayload(ProductBatch $source, string $batchNo, string $status): array
    {
        $payload = [
            'product_id' => $source->product_id,
            'batch_no' => $batchNo,
            'batch_number' => $batchNo,
            'lot_number' => $source->lot_number,
            'condition_status' => $source->condition_status ?: 'saleable',
            'parent_batch_id' => $source->id,
            'source_voucher_type' => ProductBatch::class,
            'source_voucher_id' => $source->id,
            'manufacturing_date' => $source->manufacturing_date ?: $source->mfg_date,
            'mfg_date' => $source->mfg_date ?: $source->manufacturing_date,
            'expiry_date' => $source->expiry_date,
            'purchase_price' => $source->purchase_price ?: $source->cost_price,
            'cost_price' => $source->cost_price ?: $source->purchase_price,
            'selling_price' => $source->selling_price,
            'status' => $status,
            'posted_by' => Auth::id(),
            'posted_at' => now(),
        ];

        if (Schema::hasColumn('product_batches', 'business_id')) {
            $payload['business_id'] = AppController::businessId();
        }

        if (Schema::hasColumn('product_batches', 'tenant_id')) {
            $payload['tenant_id'] = AppController::businessId();
        }

        return array_filter($payload, fn ($value, $key) => Schema::hasColumn('product_batches', $key), ARRAY_FILTER_USE_BOTH);
    }

    private function batchQuery()
    {
        $businessId = AppController::businessId();
        return ProductBatch::query()->where(fn (Builder $q) => $this->batchBusinessScope($q, $businessId));
    }

    private function batchBusinessScope($query, int $businessId): void
    {
        if (Schema::hasColumn('product_batches', 'business_id')) {
            $query->where('product_batches.business_id', $businessId);
        }
        if (Schema::hasColumn('product_batches', 'tenant_id')) {
            $method = Schema::hasColumn('product_batches', 'business_id') ? 'orWhere' : 'where';
            $query->{$method}('product_batches.tenant_id', $businessId);
        }
    }

    private function productBusinessScope($query, int $businessId): void
    {
        if (Schema::hasColumn('products', 'business_id')) {
            $query->where('products.business_id', $businessId);
        }
        if (Schema::hasColumn('products', 'company_id')) {
            $method = Schema::hasColumn('products', 'business_id') ? 'orWhere' : 'where';
            $query->{$method}('products.company_id', $businessId);
        }
    }

    private function reservationSubQuery()
    {
        return DB::table('stock_reservations')
            ->selectRaw('business_id, branch_id, warehouse_id, product_id, batch_id, COALESCE(SUM(reserved_quantity - fulfilled_quantity - released_quantity), 0) as reserved_quantity')
            ->where('status', 'active')
            ->groupBy('business_id', 'branch_id', 'warehouse_id', 'product_id', 'batch_id');
    }

    private function reservedQty(array $scope): float
    {
        return (float) DB::table('stock_reservations')
            ->where('business_id', $scope['business_id'])
            ->where('product_id', $scope['product_id'])
            ->where('batch_id', $scope['batch_id'])
            ->where('status', 'active')
            ->sum(DB::raw('reserved_quantity - fulfilled_quantity - released_quantity'));
    }

    private function availableQty(array $scope): float
    {
        return round($this->stock->getCurrentStock($scope) - $this->reservedQty($scope), 3);
    }

    private function batchNoExpression(): string
    {
        return Schema::hasColumn('product_batches', 'batch_number') ? 'COALESCE(product_batches.batch_number, product_batches.batch_no)' : 'product_batches.batch_no';
    }

    private function mfgExpression(): string
    {
        if (Schema::hasColumn('product_batches', 'manufacturing_date') && Schema::hasColumn('product_batches', 'mfg_date')) {
            return 'COALESCE(product_batches.manufacturing_date, product_batches.mfg_date)';
        }

        return Schema::hasColumn('product_batches', 'manufacturing_date') ? 'product_batches.manufacturing_date' : 'product_batches.mfg_date';
    }

    private function productColumns(): array
    {
        return array_values(array_filter(['id', 'name', 'sku', 'barcode', 'primary_barcode'], fn ($column) => Schema::hasColumn('products', $column)));
    }
}
