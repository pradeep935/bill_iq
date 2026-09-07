<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\BatchHistory;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLedger;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BatchManagementService
{
    public function __construct(private StockService $stock) {}

    public function permissions(): array
    {
        $names = ['view', 'create', 'adjust', 'transfer', 'reclassify', 'quarantine', 'release_quarantine', 'block', 'unblock', 'writeoff', 'export', 'view_ledger'];
        $allowed = (int) AppController::roleId() === 1 ? null : DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_id', AppController::roleId())->pluck('permissions.name')->all();

        return collect($names)->mapWithKeys(fn ($name) => [$name => $allowed === null || in_array('batch.'.$name, $allowed, true)])->all();
    }

    public function references(): array
    {
        return array_merge(app(MasterDataService::class)->references(['branches', 'warehouses']), [
            'products' => Product::query()->where('business_id', AppController::businessId())->where('status', 'active')->orderBy('name')->limit(300)->get(['id', 'name', 'sku']),
            'permissions' => $this->permissions(),
            'conditions' => ['saleable', 'damaged', 'expired', 'defective', 'quarantined'],
            'statuses' => ['active', 'expire_today', 'near_expiry', 'expired', 'blocked', 'quarantined', 'damaged', 'defective', 'empty'],
        ]);
    }

    public function dashboard(array $filters = []): array
    {
        $rows = $this->baseBatchRows(array_intersect_key($filters, array_flip(['branch_id', 'warehouse_id', 'product_id'])))->get();
        $positive = $rows->filter(fn ($r) => $r->quantity_on_hand > 0);
        $count = fn ($status) => $positive->where('batch_status', $status)->pluck('id')->unique()->count();
        $days = fn ($n) => $positive->filter(fn ($r) => $r->expiry_date && $r->expiry_date >= now()->toDateString() && $r->expiry_date <= now()->addDays($n)->toDateString())->pluck('id')->unique()->count();

        return [
            'active_batches' => $positive->whereIn('batch_status', ['active', 'near_expiry', 'expire_today'])->pluck('id')->unique()->count(),
            'near_expiry' => $count('near_expiry'), 'expired' => $positive->filter(fn ($r) => $r->expiry_date && $r->expiry_date < now()->toDateString())->pluck('id')->unique()->count(),
            'total_batch_quantity' => round($rows->sum('quantity_on_hand'), 3), 'total_batch_value' => round($rows->sum('batch_value'), 2),
            'blocked_batches' => $count('blocked'), 'quarantined_batches' => $count('quarantined'),
            'expire_today' => $days(0), 'expire_7_days' => $days(7), 'expire_30_days' => $days(30),
        ];
    }

    public function list(array $filters = [])
    {
        return $this->baseBatchRows($filters)->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 15))))->through(fn ($r) => $this->presentRow($r));
    }

    /** Condition/location balances and weighted receipt costs are derived from the same stock ledger as Current Stock. */
    private function baseBatchRows(array $filters = [])
    {
        $today = now()->toDateString();
        $mfg = Schema::hasColumn('product_batches', 'mfg_date') ? 'COALESCE(b.manufacturing_date,b.mfg_date)' : 'b.manufacturing_date';
        $near = now()->addDays((int) config('inventory.batch_near_expiry_days', 30))->toDateString();
        $balances = DB::table('stock_ledgers')->where('business_id', AppController::businessId())
            ->whereNotNull('batch_id')->where('stock_status', '!=', 'lost')
            ->selectRaw('batch_id, product_id, branch_id, warehouse_id, product_variant_id, stock_status as condition_status, SUM(quantity_in - quantity_out) as quantity_on_hand,
                ROUND(COALESCE(SUM(quantity_in * unit_cost) / NULLIF(SUM(quantity_in), 0), 0), 2) as average_cost,
                MAX(created_at) as last_movement, MIN(created_at) as first_receipt')
            ->groupBy('batch_id', 'product_id', 'branch_id', 'warehouse_id', 'product_variant_id', 'stock_status');
        $reserved = DB::table('stock_reservations')->where('business_id', AppController::businessId())->where('status', 'active')
            ->selectRaw('batch_id, branch_id, warehouse_id, product_variant_id, SUM(reserved_quantity - fulfilled_quantity - released_quantity) as reserved_quantity')
            ->groupBy('batch_id', 'branch_id', 'warehouse_id', 'product_variant_id');
        $base = DB::query()->fromSub($balances, 's')->join('product_batches as b', 'b.id', '=', 's.batch_id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('branches as br', 'br.id', '=', 's.branch_id')->leftJoin('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->leftJoinSub($reserved, 'r', fn ($j) => $j->on('r.batch_id', '=', 's.batch_id')
                ->whereRaw('COALESCE(r.branch_id,0) = COALESCE(s.branch_id,0) AND COALESCE(r.warehouse_id,0) = COALESCE(s.warehouse_id,0) AND COALESCE(r.product_variant_id,0) = COALESCE(s.product_variant_id,0)'))
            ->where('b.business_id', AppController::businessId())
            ->selectRaw("b.id, s.product_id, s.branch_id, s.warehouse_id, s.product_variant_id, s.condition_status,
                COALESCE(b.batch_number,b.batch_no) as batch_number, p.name as product_name, p.sku, COALESCE(p.primary_barcode,p.barcode) as barcode,
                br.name as branch_name, w.name as warehouse_name, {$mfg} as mfg_date, b.expiry_date,
                b.status as raw_status, b.blocked_reason, b.quarantined_at, s.quantity_on_hand, s.average_cost, s.last_movement, s.first_receipt,
                CASE WHEN s.condition_status = 'saleable' THEN COALESCE(r.reserved_quantity,0) ELSE 0 END as reserved_quantity,
                ROUND(s.quantity_on_hand * s.average_cost,2) as batch_value,
                CASE WHEN b.status != 'active' OR COALESCE(b.condition_status,'saleable') != 'saleable' OR s.condition_status != 'saleable' OR b.expiry_date < ? THEN 0
                    WHEN s.quantity_on_hand - COALESCE(r.reserved_quantity,0) > 0 THEN s.quantity_on_hand - COALESCE(r.reserved_quantity,0) ELSE 0 END as quantity_available,
                CASE WHEN s.quantity_on_hand <= 0 THEN 'empty'
                    WHEN b.status = 'blocked' THEN 'blocked'
                    WHEN s.condition_status = 'quarantined' OR b.status = 'quarantined' THEN 'quarantined'
                    WHEN s.condition_status != 'saleable' THEN s.condition_status
                    WHEN b.expiry_date < ? THEN 'expired' WHEN b.expiry_date = ? THEN 'expire_today'
                    WHEN b.expiry_date <= ? THEN 'near_expiry' ELSE 'active' END as batch_status", [$today, $today, $today, $near]);
        // Rank only eligible rows, within each product/location/variant. NULL expiry follows dated lots.
        $ranked = DB::query()->fromSub($base, 'q')->selectRaw('q.*, quantity_available as saleable_quantity_available,
            CASE WHEN quantity_available > 0 THEN ROW_NUMBER() OVER (PARTITION BY product_id, branch_id, warehouse_id, product_variant_id, CASE WHEN quantity_available > 0 THEN 1 ELSE 0 END ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date, first_receipt, id) ELSE NULL END as fefo_priority');
        $q = DB::query()->fromSub($ranked, 'batches');
        foreach (['product_id', 'batch_id' => 'id', 'branch_id', 'warehouse_id', 'batch_status', 'condition_status'] as $key => $column) {
            $key = is_int($key) ? $column : $key;
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $q->where($column, $filters[$key]);
            }
        }
        if (! empty($filters['search'])) {
            $q->where(fn ($s) => $s->where('batch_number', 'like', '%'.$filters['search'].'%')->orWhere('product_name', 'like', '%'.$filters['search'].'%')->orWhere('sku', 'like', '%'.$filters['search'].'%')->orWhere('barcode', 'like', '%'.$filters['search'].'%'));
        }
        foreach (['date_from' => ['first_receipt', '>='], 'date_to' => ['first_receipt', '<='], 'mfg_from' => ['mfg_date', '>='], 'expiry_to' => ['expiry_date', '<=']] as $key => [$column,$op]) {
            if (! empty($filters[$key])) {
                $q->whereDate($column, $op, $filters[$key]);
            }
        }
        $expiry = $filters['expiry_filter'] ?? '';
        if ($expiry === 'expired') {
            $q->where('expiry_date', '<', $today)->where('quantity_on_hand', '>', 0);
        }
        if (in_array($expiry, ['today', '7', '30', 'near'], true)) {
            $q->whereBetween('expiry_date', [$today, $expiry === 'near' ? $near : now()->addDays($expiry === 'today' ? 0 : (int) $expiry)->toDateString()])->where('quantity_on_hand', '>', 0);
        }
        $report = $filters['report'] ?? '';
        if ($report === 'expiry_report') {
            $q->whereNotNull('expiry_date');
        }
        if ($report === 'fefo_priority') {
            $q->where('quantity_available', '>', 0);
        }
        $statusReports = ['expire_today_report' => 'expire_today', 'near_expiry_report' => 'near_expiry', 'expired_report' => 'expired', 'blocked_report' => 'blocked', 'quarantine_report' => 'quarantined'];
        if (isset($statusReports[$report])) {
            $q->where('batch_status', $statusReports[$report]);
        }

        return $q;
    }

    private function presentRow($r)
    {
        if ($r->condition_status === 'quarantined') {
            $r->quarantined_at = $r->first_receipt;
        }
        $r->days_remaining = $r->expiry_date ? (int) now()->startOfDay()->diffInDays(Carbon::parse($r->expiry_date), false) : null;
        $r->status_label = $r->batch_status === 'empty' ? 'Depleted' : str($r->batch_status)->replace('_', ' ')->title()->toString();
        $r->actions = ['view', 'movements'];
        if ($r->quantity_on_hand <= 0) {
            return $r;
        }
        if ($r->raw_status === 'blocked') {
            $r->actions[] = 'unblock';

            return $r;
        }
        $r->actions = array_merge($r->actions, ['adjust', 'reclassify', 'writeoff']);
        if ($r->condition_status === 'quarantined' || $r->raw_status === 'quarantined') {
            $r->actions[] = 'release_quarantine';
        } elseif ($r->quantity_available > 0) {
            $r->actions = array_merge($r->actions, ['transfer', 'quarantine', 'block']);
        }

        return $r;
    }

    public function fefo(array $filters): ?array
    {
        if (empty($filters['product_id'])) {
            return null;
        }
        $row = $this->baseBatchRows($filters)->where('quantity_available', '>', 0)->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('first_receipt')->orderBy('id')->first();

        return $row ? (array) $this->presentRow($row) : null;
    }

    private function movementQuery(array $filters)
    {
        return StockLedger::query()->with(['product', 'batch', 'branch', 'warehouse', 'creator'])
            ->where('business_id', AppController::businessId())->whereNotNull('batch_id')
            ->when(! empty($filters['batch_id']), fn ($q) => $q->where('batch_id', $filters['batch_id']))
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('product_id', $filters['product_id']))
            ->when(! empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(! empty($filters['warehouse_id']), fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(! empty($filters['movement_type']), fn ($q) => $q->where('transaction_type', $filters['movement_type']))
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('transaction_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('transaction_date', '<=', $filters['date_to']))
            ->when(! empty($filters['search']), fn ($q) => $q->where(fn ($s) => $s->where('remarks', 'like', '%'.$filters['search'].'%')->orWhereHas('batch', fn ($b) => $b->where('batch_no', 'like', '%'.$filters['search'].'%'))->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$filters['search'].'%')->orWhere('sku', 'like', '%'.$filters['search'].'%'))));
    }

    public function movements(array $filters = [])
    {
        $page = $this->movementQuery($filters)->latest('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 15))));
        $page->setCollection(collect($this->presentLedger($page->getCollection())));

        return $page;
    }

    private function presentLedger($rows): array
    {
        $numbers = $this->stock->stockReferenceNumbers($rows);
        // Pair transfer / condition legs within the source voucher, including legs outside the current page/filter.
        $siblings = StockLedger::query()->with(['branch', 'warehouse'])->where('business_id', AppController::businessId())
            ->whereIn('reference_id', $rows->pluck('reference_id'))->whereIn('reference_type', $rows->pluck('reference_type'))->get();

        return $rows->map(function ($r) use ($numbers, $siblings) {
            $pair = $siblings->first(fn ($s) => $s->id !== $r->id && $s->reference_type === $r->reference_type && $s->reference_id === $r->reference_id && $s->batch_id === $r->batch_id && $s->product_variant_id === $r->product_variant_id && (($r->quantity_in > 0 && $s->quantity_out > 0) || ($r->quantity_out > 0 && $s->quantity_in > 0)));
            $out = $r->quantity_out > 0 ? $r : $pair;
            $in = $r->quantity_in > 0 ? $r : $pair;

            return [
                'id' => $r->id, 'date' => ($r->posted_at ?: $r->created_at)?->format('Y-m-d H:i'), 'document_date' => $r->transaction_date?->format('Y-m-d'),
                'type' => $r->transaction_type, 'movement_label' => str($r->transaction_type)->replace('_', ' ')->title()->toString(),
                'voucher' => $numbers[$r->reference_type.':'.$r->reference_id] ?? $r->remarks ?? 'Inventory operation',
                'batch_number' => $r->batch?->batch_no ?: $r->batch?->batch_number, 'product_name' => $r->product?->name,
                'branch' => $r->branch?->name, 'warehouse' => $r->warehouse?->name,
                'from_branch' => $out?->branch?->name, 'to_branch' => $in?->branch?->name, 'from_warehouse' => $out?->warehouse?->name, 'to_warehouse' => $in?->warehouse?->name,
                'from_condition' => $out?->stock_status, 'to_condition' => $in?->stock_status,
                'in' => (float) $r->quantity_in, 'out' => (float) $r->quantity_out, 'net_qty' => round($r->quantity_in - $r->quantity_out, 3),
                'cost' => (float) $r->unit_cost, 'stock_value' => round(($r->quantity_in - $r->quantity_out) * $r->unit_cost, 2),
                'user' => $r->creator?->name ?: 'System', 'remarks' => $r->remarks,
            ];
        })->all();
    }

    public function detail(int $batchId, array $filters = []): array
    {
        $batch = $this->batch($batchId);
        $rows = $this->baseBatchRows(array_merge($filters, ['batch_id' => $batchId]))->get()->map(fn ($r) => $this->presentRow($r));

        return ['batch' => ['id' => $batch->id, 'batch_number' => $batch->batch_no ?: $batch->batch_number, 'product' => $batch->product?->name, 'sku' => $batch->product?->sku, 'mfg_date' => $batch->manufacturing_date?->format('Y-m-d'), 'expiry_date' => $batch->expiry_date?->format('Y-m-d'), 'status' => $batch->status],
            'balances' => $rows, 'summary' => ['current_qty' => $rows->sum('quantity_on_hand'), 'available_qty' => $rows->sum('quantity_available'), 'reserved_qty' => $rows->sum('reserved_quantity'), 'batch_value' => $rows->sum('batch_value')],
            'ledger' => $this->presentLedger($this->movementQuery(array_merge($filters, ['batch_id' => $batchId]))->latest('id')->limit(80)->get()),
            'history' => BatchHistory::query()->with('creator')->where('business_id', AppController::businessId())->where('batch_id', $batchId)->latest('id')->limit(80)->get()->map(fn ($h) => ['date' => $h->created_at?->format('Y-m-d H:i'), 'event_type' => $h->event_type, 'remarks' => $h->remarks, 'user' => $h->creator?->name ?: 'System']),
        ];
    }

    public function ledger(int $batchId, array $filters = []): array
    {
        $this->batch($batchId);

        return $this->presentLedger($this->movementQuery(array_merge($filters, ['batch_id' => $batchId]))->latest('id')->limit(500)->get());
    }

    public function reports(array $filters = [])
    {
        return ($filters['report'] ?? '') === 'batch_movement' ? $this->movements($filters) : $this->list($filters);
    }

    public function exportRows(array $filters): array
    {
        if (($filters['report'] ?? '') === 'batch_movement') {
            $rows = $this->presentLedger($this->movementQuery($filters)->orderBy('id')->get());
            $columns = ['date', 'document_date', 'movement_label', 'voucher', 'batch_number', 'product_name', 'from_branch', 'to_branch', 'from_warehouse', 'to_warehouse', 'from_condition', 'to_condition', 'in', 'out', 'net_qty', 'cost', 'stock_value', 'user', 'remarks'];
        } else {
            $rows = $this->baseBatchRows($filters)->orderBy('id')->get()->map(fn ($r) => (array) $this->presentRow($r));
            $columns = ['batch_number', 'product_name', 'sku', 'branch_name', 'warehouse_name', 'condition_status', 'mfg_date', 'expiry_date', 'days_remaining', 'quantity_on_hand', 'quantity_available', 'reserved_quantity', 'average_cost', 'batch_value', 'status_label', 'fefo_priority'];
        }

        return ['headers' => array_map(fn ($c) => str($c)->replace('_', ' ')->title()->toString(), $columns), 'rows' => collect($rows)->map(fn ($r) => array_map(fn ($c) => $r[$c] ?? '', $columns))->all()];
    }

    public function operation(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $operation = $data['operation'];
            if (! in_array($operation, ['opening', 'stock_in', 'adjust', 'transfer', 'reclassify', 'quarantine', 'release_quarantine', 'block', 'unblock', 'writeoff'], true)) {
                throw ValidationException::withMessages(['operation' => 'Invalid batch operation.']);
            }
            if (! in_array($operation, ['opening', 'stock_in'], true) && empty($data['batch_id'])) {
                throw ValidationException::withMessages(['batch_id' => 'Select an existing batch.']);
            }
            if (in_array($operation, ['block', 'unblock'], true)) {
                $batch = $this->updateStatus((int) $data['batch_id'], $operation === 'block' ? 'blocked' : 'active', $data['reason']);

                return ['batch_id' => $batch->id];
            }
            $warehouse = Warehouse::query()->where('business_id', $businessId)->where('branch_id', $data['branch_id'])->findOrFail($data['warehouse_id']);
            $batch = ! empty($data['batch_id']) ? $this->batch((int) $data['batch_id']) : null;
            $productId = $batch?->product_id ?: $data['product_id'];
            $product = Product::query()->where('business_id', $businessId)->whereKey($productId)->lockForUpdate()->firstOrFail();
            if (($product->expiry_required || $product->tracking_type === 'batch_expiry') && ! $batch?->expiry_date && empty($data['expiry_date'])) {
                throw ValidationException::withMessages(['expiry_date' => 'Expiry date is required for this product.']);
            }
            $token = $data['operation_token'] ?? null;
            $hashData = $data;
            unset($hashData['operation_token']);
            ksort($hashData);
            $hash = hash('sha256', json_encode($hashData));
            if ($token) {
                $previous = BatchHistory::query()->where('business_id', $businessId)->where('operation_token', $token)->lockForUpdate()->first();
                if ($previous) {
                    if ($previous->request_hash !== $hash) {
                        throw ValidationException::withMessages(['operation_token' => 'This operation was already posted with different details. Start a new operation.']);
                    }

                    return ['batch_id' => $previous->batch_id, 'replayed' => true];
                }
            }
            $batch = $batch ? $this->batch($batch->id, true) : app(BatchIdentityService::class)->resolve($businessId, $productId, $data);
            if ($batch->status === 'blocked') {
                throw ValidationException::withMessages(['batch_id' => 'Unblock this batch before performing a stock operation.']);
            }
            $condition = $data['condition_status'] ?? 'saleable';
            $scope = ['business_id' => $businessId, 'product_id' => $productId, 'batch_id' => $batch->id, 'branch_id' => $data['branch_id'], 'warehouse_id' => $warehouse->id, 'product_variant_id' => $data['product_variant_id'] ?? null, 'stock_status' => $condition];
            $qty = (float) $data['quantity'];
            $cost = $this->stock->getAverageCost($scope);
            $reclass = in_array($operation, ['reclassify', 'quarantine', 'release_quarantine'], true);
            $target = $operation === 'quarantine' ? 'quarantined' : ($operation === 'release_quarantine' ? ($data['to_condition'] ?? 'saleable') : ($data['to_condition'] ?? $condition));
            if ($reclass && $target === 'saleable' && ($batch->expiry_date && $batch->expiry_date->lt(now()->startOfDay()))) {
                throw ValidationException::withMessages(['to_condition' => 'Expired stock cannot be released to saleable.']);
            }
            if ($operation === 'release_quarantine' && $condition !== 'quarantined') {
                throw ValidationException::withMessages(['condition_status' => 'Select quarantined stock to release.']);
            }
            if ($reclass || in_array($operation, ['transfer', 'writeoff'], true) || ($operation === 'adjust' && ($data['direction'] ?? '') === 'out')) {
                $balance = $this->stock->getConditionStock($scope, $condition);
                $reserved = $condition === 'saleable' ? $this->stock->getActiveReservedQuantity($scope, null, true) : 0;
                if ($qty > $balance - $reserved + 0.00001) {
                    throw ValidationException::withMessages(['quantity' => 'Quantity exceeds unreserved stock in this condition and location.']);
                }
            }
            if ($operation === 'transfer') {
                $destination = Warehouse::query()->where('business_id', $businessId)->where('branch_id', $data['destination_branch_id'])->findOrFail($data['destination_warehouse_id']);
                if ($destination->id === $warehouse->id) {
                    throw ValidationException::withMessages(['destination_warehouse_id' => 'Source and destination must differ.']);
                }
                $control = app(InventoryControlService::class);
                // Existing transfer voucher provides document identity and paired stock entries.
                if ($condition !== 'saleable' || ! $batch->isSaleEligible()) {
                    throw ValidationException::withMessages(['batch_id' => 'Only eligible saleable batch stock can be transferred.']);
                }
                $v = $control->saveTransfer(['source_branch_id' => $data['branch_id'], 'source_warehouse_id' => $warehouse->id, 'destination_branch_id' => $data['destination_branch_id'], 'destination_warehouse_id' => $destination->id, 'transfer_date' => now()->toDateString(), 'transfer_type' => 'immediate', 'status' => 'draft', 'remarks' => $data['reason'], 'items' => [['product_id' => $productId, 'product_variant_id' => $data['product_variant_id'] ?? null, 'source_batch_id' => $batch->id, 'destination_batch_id' => $batch->id, 'requested_quantity' => $qty, 'unit_cost' => $cost]]]);
                $control->postImmediateTransfer($v->id);
            } elseif ($operation === 'opening') {
                $v = app(OpeningStockService::class)->create(['branch_id' => $data['branch_id'], 'warehouse_id' => $warehouse->id, 'opening_date' => $data['document_date'] ?? now()->toDateString(), 'status' => 'posted', 'remarks' => $data['reason'], 'items' => [['product_id' => $productId, 'product_variant_id' => $data['product_variant_id'] ?? null, 'batch_id' => $batch->id, 'batch_no' => $batch->batch_no, 'manufacturing_date' => $batch->manufacturing_date, 'expiry_date' => $batch->expiry_date, 'quantity' => $qty, 'purchase_cost' => $data['unit_cost'], 'condition_status' => $condition]]]);
            } elseif ($reclass) {
                $v = $this->conditionVoucher($batch, $data, $qty, $cost, $condition, $target);
            } else {
                $in = $operation === 'stock_in' || ($operation === 'adjust' && ($data['direction'] ?? '') === 'in');
                $v = app(InventoryControlService::class)->saveAdjustment(['branch_id' => $data['branch_id'], 'warehouse_id' => $warehouse->id, 'adjustment_date' => $data['document_date'] ?? now()->toDateString(), 'adjustment_type' => $in ? 'increase' : 'decrease', 'source' => 'manual', 'status' => 'posted', 'remarks' => $data['reason'], 'items' => [['product_id' => $productId, 'product_variant_id' => $data['product_variant_id'] ?? null, 'batch_id' => $batch->id, 'adjustment_quantity' => $qty, 'direction' => $in ? 'in' : 'out', 'unit_cost' => $in ? $data['unit_cost'] : $cost, 'condition_status' => $condition, 'reason' => ($operation === 'writeoff' ? 'Write-off: ' : '').$data['reason']]]]);
            }
            BatchHistory::query()->create(['business_id' => $businessId, 'batch_id' => $batch->id, 'product_id' => $productId, 'branch_id' => $data['branch_id'], 'warehouse_id' => $warehouse->id, 'event_type' => $operation, 'operation_token' => $token, 'request_hash' => $hash, 'quantity' => $qty, 'from_condition' => $condition, 'to_condition' => $reclass ? $target : $condition, 'voucher_type' => get_class($v), 'voucher_id' => $v->id, 'remarks' => $data['reason'], 'created_by' => Auth::id()]);

            return ['batch_id' => $batch->id, 'document_number' => $v->voucher_number];
        }, 3);
    }

    private function conditionVoucher(ProductBatch $batch, array $data, float $qty, float $cost, string $from, string $to)
    {
        return app(InventoryControlService::class)->saveAdjustment(['branch_id' => $data['branch_id'], 'warehouse_id' => $data['warehouse_id'], 'adjustment_date' => $data['document_date'] ?? now()->toDateString(), 'adjustment_type' => 'condition_transfer', 'source' => 'condition_transfer', 'status' => 'posted', 'remarks' => $data['reason'], 'items' => [['product_id' => $batch->product_id, 'product_variant_id' => $data['product_variant_id'] ?? null, 'batch_id' => $batch->id, 'adjustment_quantity' => $qty, 'direction' => 'transfer', 'unit_cost' => $cost, 'source_condition_status' => $from, 'destination_condition_status' => $to, 'condition_status' => $to, 'reason' => $data['reason']]]]);
    }

    public function updateStatus(int $batchId, string $status, ?string $reason = null, ?string $releaseOutcome = null): ProductBatch
    {
        if (! in_array($status, ['active', 'blocked'], true)) {
            throw ValidationException::withMessages(['status' => 'Use a quarantine stock operation to change condition.']);
        }
        if (! trim($reason ?? '')) {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return DB::transaction(function () use ($batchId, $status, $reason) {
            $existing = $this->batch($batchId);
            Product::query()->whereKey($existing->product_id)->lockForUpdate()->firstOrFail();
            $batch = $this->batch($batchId, true);
            if (($status === 'active' && $batch->status !== 'blocked') || ($status === 'blocked' && $batch->status === 'blocked')) {
                throw ValidationException::withMessages(['status' => 'This status action is no longer valid. Refresh the batch.']);
            }
            $from = $batch->status;
            $batch->update(['status' => $status, 'blocked_reason' => $status === 'blocked' ? $reason : null, $status === 'blocked' ? 'blocked_at' : 'unblocked_at' => now(), $status === 'blocked' ? 'blocked_by' : 'unblocked_by' => Auth::id()]);
            BatchHistory::query()->create(['business_id' => AppController::businessId(), 'batch_id' => $batch->id, 'product_id' => $batch->product_id, 'event_type' => $status === 'blocked' ? 'blocked' : 'unblocked', 'from_status' => $from, 'to_status' => $status, 'remarks' => $reason, 'created_by' => Auth::id()]);

            return $batch;
        }, 3);
    }

    public function transfer(int $batchId, array $data): array
    {
        return $this->operation(array_merge($data, ['operation' => 'transfer', 'batch_id' => $batchId, 'branch_id' => $data['source_branch_id'], 'warehouse_id' => $data['source_warehouse_id'], 'reason' => $data['remarks'] ?? 'Batch transfer', 'condition_status' => 'saleable']));
    }

    private function batch(int $id, bool $lock = false): ProductBatch
    {
        return ProductBatch::query()->with('product')->where('business_id',AppController::businessId())->when($lock,fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }
}
