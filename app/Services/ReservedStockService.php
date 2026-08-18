<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\DeliveryChallan;
use App\Models\SalesOrder;
use App\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReservedStockService
{
    private const TABS = ['active', 'expiring', 'dispatched', 'released', 'ledger'];
    private const PER_PAGE = [10, 25, 50, 100];
    private const SORTS = ['number', 'date', 'customer', 'warehouse', 'remaining_quantity', 'reserved_value', 'expiry', 'status', 'created_at'];
    private const EXPIRY_WARNING_DAYS = 3;

    public function payload(array $input): array
    {
        $filters = $this->filters($input);

        return [
            'module' => 'reserved-stock',
            'filters' => $filters,
            'references' => $this->references(),
            'summary' => $this->summary($filters),
            'rows' => [
                'active' => $filters['tab'] === 'active' ? $this->reservations($filters) : $this->emptyPage($filters),
                'expiring' => $filters['tab'] === 'expiring' ? $this->reservations($filters) : $this->emptyPage($filters),
                'dispatched' => $filters['tab'] === 'dispatched' ? $this->reservations($filters) : $this->emptyPage($filters),
                'released' => $filters['tab'] === 'released' ? $this->reservations($filters) : $this->emptyPage($filters),
                'ledger' => $filters['tab'] === 'ledger' ? $this->ledger($filters) : $this->emptyPage($filters),
            ],
            'permissions' => $this->permissions(),
            'stock_rule' => 'Reserved stock reduces available-to-sell only. Physical stock remains unchanged until a real stock ledger transaction is posted from invoice or Stock Outward.',
        ];
    }

    public function reservations(array $input): LengthAwarePaginator
    {
        $filters = $this->filters($input);
        $sort = in_array($filters['sort'], self::SORTS, true) ? $filters['sort'] : 'date';
        $column = [
            'number' => 'reservation_number',
            'date' => 'reserved_date',
            'customer' => 'customer_name',
            'warehouse' => 'warehouse_name',
            'remaining_quantity' => 'remaining_quantity',
            'reserved_value' => 'reserved_value',
            'expiry' => 'expiry',
            'status' => 'status',
            'created_at' => 'created_at',
        ][$sort];

        return $this->reservationQuery($filters)
            ->orderBy($column, $filters['direction'])
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    public function ledger(array $input): LengthAwarePaginator
    {
        $filters = $this->filters($input);

        return $this->ledgerQuery($filters)
            ->orderBy('date', $filters['direction'])
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    public function summary(array $input): array
    {
        $filters = $this->filters($input);
        $active = $this->reservationQuery(array_merge($filters, ['tab' => 'active']))->get();
        $expiring = $this->reservationQuery(array_merge($filters, ['tab' => 'expiring']))->count();
        $dispatched = $this->reservationQuery(array_merge($filters, ['tab' => 'dispatched']))->count();
        $unallocated = (clone $this->lineBaseQuery($filters))->whereNull('stock_reservations.batch_id')->where('stock_reservations.status', 'active')->count();

        return [
            'active_reservations' => $active->count(),
            'reserved_quantity' => round((float) $active->sum('remaining_quantity'), 3),
            'reserved_value' => round((float) $active->sum('reserved_value'), 2),
            'expiring_soon' => $expiring,
            'pending_dispatch' => $active->where('remaining_quantity', '>', 0)->count(),
            'unallocated_reservations' => $unallocated,
            'dispatched_reservations' => $dispatched,
            'released_reservations' => $this->reservationQuery(array_merge($filters, ['tab' => 'released']))->count(),
        ];
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        $branches = $this->allowedBranchIds();

        return [
            'tabs' => self::TABS,
            'per_page' => self::PER_PAGE,
            'statuses' => ['active', 'partially_dispatched', 'fully_dispatched', 'released', 'expired', 'cancelled'],
            'source_types' => ['sales_order', 'manual_reservation', 'transfer_request'],
            'expiry_statuses' => ['expires_today', 'expiring_soon', 'expired'],
            'customers' => Schema::hasTable('customers')
                ? DB::table('customers')->where('business_id', $businessId)->orderBy('customer_name')->limit(500)->get(['id', 'customer_name', 'customer_code', 'mobile'])
                : collect(),
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('business_id', $businessId)->when($branches, fn ($q) => $q->whereIn('id', $branches))->orderBy('name')->get(['id', 'name', 'code'])
                : collect(),
            'warehouses' => Schema::hasTable('warehouses')
                ? DB::table('warehouses')->where('business_id', $businessId)->when($branches, fn ($q) => $q->whereIn('branch_id', $branches))->orderBy('name')->get(['id', 'branch_id', 'name', 'code'])
                : collect(),
        ];
    }

    public function availability(array $input): array
    {
        $filters = $this->filters($input);
        $productId = (int) ($input['product_id'] ?? 0);
        abort_unless($productId > 0, 422, 'Product is required.');

        $scope = [
            'business_id' => AppController::businessId(),
            'branch_id' => $filters['branch_id'],
            'warehouse_id' => $filters['warehouse_id'],
            'product_id' => $productId,
            'product_variant_id' => $input['product_variant_id'] ?? null,
            'batch_id' => $input['batch_id'] ?? null,
        ];

        $physical = $this->physicalStock($scope);
        $reserved = $this->activeReservedQuantity($scope, $input['current_reservation_id'] ?? null);

        return [
            'physical_stock' => $physical,
            'active_reserved_quantity' => $reserved,
            'available_to_sell' => round($physical - $reserved, 3),
            'current_reservation_id' => $input['current_reservation_id'] ?? null,
        ];
    }

    public function export(array $input): array
    {
        $filters = $this->filters($input);
        if ($filters['tab'] === 'ledger') {
            return [
                'filename' => 'reservation-history-' . now()->toDateString() . '.csv',
                'headers' => ['Date', 'Reservation', 'Product', 'Action', 'Quantity', 'Previous remaining', 'New remaining', 'Reference', 'User'],
                'rows' => $this->ledgerQuery($filters)->orderBy('date')->limit(5000)->get()->map(fn ($row) => [
                    $row->date, $row->reservation_number, $row->product_name, $row->action, $row->quantity, $row->previous_remaining, $row->new_remaining, $row->reference, $row->performed_by,
                ])->all(),
            ];
        }

        return [
            'filename' => 'reserved-stock-' . now()->toDateString() . '.csv',
            'headers' => ['Reservation number', 'Reservation date', 'Source type', 'Source number', 'Customer', 'Branch', 'Warehouse', 'Product count', 'Reserved quantity', 'Dispatched quantity', 'Released quantity', 'Remaining quantity', 'Reserved value', 'Expiry date', 'Status', 'Created by'],
            'rows' => $this->reservationQuery($filters)->orderBy('reserved_date')->limit(5000)->get()->map(fn ($row) => [
                $row->reservation_number, $row->reserved_date, $row->source_type, $row->source_number, $row->customer_name, $row->branch_name, $row->warehouse_name, $row->product_lines, $row->reserved_quantity, $row->dispatched_quantity, $row->released_quantity, $row->remaining_quantity, $row->reserved_value, $row->expiry, $row->status, $row->created_by_name,
            ])->all(),
        ];
    }

    public function release(int $reservation, ?string $reason = null): SalesOrder
    {
        abort_unless($this->permissions()['release'], 403);
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Release reason is required.']);
        }

        $order = $this->orderForReservation($reservation);
        $released = app(OrderManagementService::class)->releaseSalesOrderReservation($order->id, $reason);
        AuditLogger::record([
            'module_name' => 'reserved_stock',
            'record_id' => $order->id,
            'action_type' => 'Reservation Released',
            'changes' => [['field_name' => 'reason', 'old_value' => null, 'new_value' => $reason]],
        ]);

        return $released;
    }

    public function extend(int $reservation, string $expiryDate, ?string $reason = null): void
    {
        abort_unless($this->permissions()['extend'], 403);
        $order = $this->orderForReservation($reservation);
        if ($expiryDate < now()->toDateString()) {
            throw ValidationException::withMessages(['expiry_date' => 'Expiry date cannot be in the past.']);
        }

        DB::transaction(function () use ($order, $expiryDate, $reason) {
            DB::table('stock_reservations')
                ->where('business_id', AppController::businessId())
                ->where('reference_type', SalesOrder::class)
                ->where('reference_id', $order->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->update(['expires_at' => $expiryDate, 'updated_at' => now()]);

            AuditLogger::record([
                'module_name' => 'reserved_stock',
                'record_id' => $order->id,
                'action_type' => 'Reservation Extended',
                'changes' => [['field_name' => 'expires_at', 'old_value' => null, 'new_value' => $expiryDate], ['field_name' => 'reason', 'old_value' => null, 'new_value' => $reason]],
            ]);
        });
    }

    public function dispatch(int $reservation): DeliveryChallan
    {
        abort_unless($this->permissions()['dispatch'], 403);
        $order = $this->orderForReservation($reservation);
        $challan = app(OrderManagementService::class)->createDeliveryChallanFromOrder($order->id, false);
        AuditLogger::record([
            'module_name' => 'reserved_stock',
            'record_id' => $order->id,
            'action_type' => 'Reservation Sent to Stock Outward',
            'changes' => [['field_name' => 'dispatch_queue', 'old_value' => null, 'new_value' => $challan->challan_number]],
        ]);

        return $challan;
    }

    public function printHtml(array $input): string
    {
        $filters = $this->filters($input);
        $rows = ($filters['tab'] === 'ledger' ? $this->ledgerQuery($filters) : $this->reservationQuery($filters))->limit(500)->get();
        $title = $filters['tab'] === 'ledger' ? 'Reservation History Report' : 'Reserved Stock Report';
        $totalQty = $rows->sum(fn ($row) => (float) ($row->remaining_quantity ?? $row->quantity ?? 0));
        $totalValue = $rows->sum(fn ($row) => (float) ($row->reserved_value ?? 0));
        $body = $rows->map(fn ($row) => '<tr><td>' . e($row->reserved_date ?? $row->date) . '</td><td>' . e($row->reservation_number) . '</td><td>' . e($row->customer_name ?? $row->product_name ?? '-') . '</td><td>' . e($row->warehouse_name ?? '-') . '</td><td>' . e($row->remaining_quantity ?? $row->quantity ?? '-') . '</td><td>' . e($row->status ?? $row->action) . '</td></tr>')->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title><style>body{font-family:Arial,sans-serif;color:#111;margin:24px}.muted{color:#667085}.totals{display:flex;gap:20px;margin:12px 0}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f8fafc}.print{margin-top:20px}@media print{.print{display:none}}</style></head><body><h1>' . e($title) . '</h1><p class="muted">Generated ' . e(now()->format('Y-m-d H:i')) . ' by ' . e(Auth::user()->name ?? 'System') . '</p><p class="muted">Date range: ' . e($filters['date_from'] ?: 'All') . ' to ' . e($filters['date_to'] ?: 'All') . '</p><div class="totals"><strong>Total remaining: ' . e(number_format((float) $totalQty, 3)) . '</strong><strong>Reserved value: Rs. ' . e(number_format((float) $totalValue, 2)) . '</strong></div><table><thead><tr><th>Date</th><th>Reservation</th><th>Customer / Product</th><th>Warehouse</th><th>Qty</th><th>Status</th></tr></thead><tbody>' . $body . '</tbody></table><button class="print" onclick="window.print()">Print</button></body></html>';
    }

    public function filters(array $input): array
    {
        $requestedTab = $input['tab'] ?? 'active';
        $tab = in_array($requestedTab, self::TABS, true) ? $requestedTab : 'active';
        $perPage = in_array((int) ($input['per_page'] ?? 25), self::PER_PAGE, true) ? (int) ($input['per_page'] ?? 25) : 25;

        return [
            'tab' => $tab,
            'search' => trim((string) ($input['search'] ?? $input['q'] ?? '')),
            'per_page' => $perPage,
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'date_from' => $input['date_from'] ?? null,
            'date_to' => $input['date_to'] ?? null,
            'branch_id' => $this->allowedId($input['branch_id'] ?? null, 'branch'),
            'warehouse_id' => $this->allowedId($input['warehouse_id'] ?? null, 'warehouse'),
            'customer_id' => $input['customer_id'] ?? null,
            'product_id' => $input['product_id'] ?? null,
            'product_variant_id' => $input['product_variant_id'] ?? null,
            'batch_id' => $input['batch_id'] ?? null,
            'created_by' => $input['created_by'] ?? null,
            'status' => $input['status'] ?? null,
            'expiry_status' => $input['expiry_status'] ?? null,
            'source_type' => $input['source_type'] ?? null,
            'dispatch_status' => $input['dispatch_status'] ?? null,
            'sort' => $input['sort'] ?? 'date',
            'direction' => strtolower((string) ($input['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    private function reservationQuery(array $filters): Builder
    {
        $base = $this->groupedReservationQuery($filters);
        $query = DB::query()->fromSub($base, 'reservations');
        $today = now()->toDateString();
        $warning = now()->addDays(self::EXPIRY_WARNING_DAYS)->toDateString();

        return $query
            ->when($filters['tab'] === 'active', fn ($q) => $q->where('remaining_quantity', '>', 0)->whereNotIn('status', ['released', 'fully_dispatched', 'expired', 'cancelled']))
            ->when($filters['tab'] === 'expiring', fn ($q) => $q->where('remaining_quantity', '>', 0)->whereNotNull('expiry')->whereDate('expiry', '<=', $warning))
            ->when($filters['tab'] === 'dispatched', fn ($q) => $q->where('dispatched_quantity', '>', 0))
            ->when($filters['tab'] === 'released', fn ($q) => $q->where(fn ($released) => $released->whereIn('status', ['released', 'expired', 'cancelled'])->orWhere('released_quantity', '>', 0)))
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['expiry_status'] ?? null) === 'expires_today', fn ($q) => $q->whereDate('expiry', $today))
            ->when(($filters['expiry_status'] ?? null) === 'expiring_soon', fn ($q) => $q->whereBetween('expiry', [$today, $warning]))
            ->when(($filters['expiry_status'] ?? null) === 'expired', fn ($q) => $q->whereDate('expiry', '<', $today));
    }

    private function groupedReservationQuery(array $filters): Builder
    {
        $query = $this->lineBaseQuery($filters)
            ->leftJoin('sales_order_items', function ($join) {
                $join->on('sales_order_items.sales_order_id', '=', 'sales_orders.id')
                    ->on('sales_order_items.product_id', '=', 'stock_reservations.product_id')
                    ->whereRaw('COALESCE(sales_order_items.product_variant_id, 0) = COALESCE(stock_reservations.product_variant_id, 0)')
                    ->whereRaw('COALESCE(sales_order_items.batch_id, 0) = COALESCE(stock_reservations.batch_id, 0)');
            })
            ->selectRaw("stock_reservations.reference_id as id, CONCAT('RSV-', stock_reservations.reference_id) as reservation_number, MIN(stock_reservations.created_at) as reserved_date, MIN(stock_reservations.created_at) as created_at, 'Sales Order' as source_type, COALESCE(sales_orders.order_number, stock_reservations.reference_id) as source_number, sales_orders.customer_id, customers.customer_name, customers.customer_code, customers.mobile, stock_reservations.branch_id, branches.name as branch_name, stock_reservations.warehouse_id, warehouses.name as warehouse_name, stock_reservations.created_by, users.name as created_by_name, COUNT(DISTINCT stock_reservations.product_id) as product_lines, COALESCE(SUM(stock_reservations.reserved_quantity), 0) as reserved_quantity, COALESCE(SUM(stock_reservations.fulfilled_quantity), 0) as dispatched_quantity, COALESCE(SUM(stock_reservations.released_quantity), 0) as released_quantity, COALESCE(SUM(stock_reservations.reserved_quantity - stock_reservations.fulfilled_quantity - stock_reservations.released_quantity), 0) as remaining_quantity, COALESCE(SUM((stock_reservations.reserved_quantity - stock_reservations.fulfilled_quantity - stock_reservations.released_quantity) * COALESCE(sales_order_items.unit_price, 0)), 0) as reserved_value, MAX(stock_reservations.expires_at) as expiry, sales_orders.dispatch_status, 'Available-to-sell only' as stock_effect, MAX(stock_reservations.updated_at) as updated_at, CASE WHEN MAX(stock_reservations.status) = 'released' THEN 'released' WHEN COALESCE(SUM(stock_reservations.fulfilled_quantity),0) >= COALESCE(SUM(stock_reservations.reserved_quantity - stock_reservations.released_quantity),0) AND COALESCE(SUM(stock_reservations.reserved_quantity),0) > 0 THEN 'fully_dispatched' WHEN COALESCE(SUM(stock_reservations.fulfilled_quantity),0) > 0 THEN 'partially_dispatched' WHEN MAX(stock_reservations.expires_at) IS NOT NULL AND MAX(stock_reservations.expires_at) < CURRENT_DATE THEN 'expired' ELSE 'active' END as status")
            ->groupBy('stock_reservations.reference_id', 'sales_orders.order_number', 'sales_orders.customer_id', 'customers.customer_name', 'customers.customer_code', 'customers.mobile', 'stock_reservations.branch_id', 'branches.name', 'stock_reservations.warehouse_id', 'warehouses.name', 'stock_reservations.created_by', 'users.name', 'sales_orders.dispatch_status');

        return $query;
    }

    private function lineBaseQuery(array $filters): Builder
    {
        $query = DB::table('stock_reservations')
            ->leftJoin('sales_orders', function ($join) {
                $join->on('sales_orders.id', '=', 'stock_reservations.reference_id')
                    ->where('stock_reservations.reference_type', SalesOrder::class);
            })
            ->leftJoin('customers', 'customers.id', '=', 'sales_orders.customer_id')
            ->leftJoin('branches', 'branches.id', '=', 'stock_reservations.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'stock_reservations.warehouse_id')
            ->leftJoin('products', 'products.id', '=', 'stock_reservations.product_id')
            ->leftJoin('product_variant_items', 'product_variant_items.id', '=', 'stock_reservations.product_variant_id')
            ->leftJoin('product_batches', 'product_batches.id', '=', 'stock_reservations.batch_id')
            ->leftJoin('users', 'users.id', '=', 'stock_reservations.created_by')
            ->where('stock_reservations.business_id', AppController::businessId());

        $this->applyLineFilters($query, $filters);

        return $query;
    }

    private function ledgerQuery(array $filters): Builder
    {
        $query = $this->lineBaseQuery($filters)
            ->selectRaw("stock_reservations.id, stock_reservations.updated_at as date, CONCAT('RSV-', stock_reservations.reference_id) as reservation_number, products.name as product_name, product_variant_items.sku as variant_name, COALESCE(product_batches.batch_no, product_batches.batch_number) as batch_number, warehouses.name as warehouse_name, CASE WHEN stock_reservations.released_quantity > 0 THEN 'Released' WHEN stock_reservations.fulfilled_quantity > 0 THEN 'Dispatched' WHEN stock_reservations.status = 'released' THEN 'Released' ELSE 'Reserved' END as action, CASE WHEN stock_reservations.released_quantity > 0 THEN stock_reservations.released_quantity WHEN stock_reservations.fulfilled_quantity > 0 THEN stock_reservations.fulfilled_quantity ELSE stock_reservations.reserved_quantity END as quantity, stock_reservations.reserved_quantity as previous_remaining, (stock_reservations.reserved_quantity - stock_reservations.fulfilled_quantity - stock_reservations.released_quantity) as new_remaining, COALESCE(sales_orders.order_number, stock_reservations.reference_id) as reference, users.name as performed_by");

        return $query
            ->when(!empty($filters['status']), fn ($q) => $q->where('stock_reservations.status', $filters['status']));
    }

    private function applyLineFilters(Builder $query, array $filters): void
    {
        $allowedBranches = $this->allowedBranchIds();
        $query
            ->when($allowedBranches, fn ($q) => $q->whereIn('stock_reservations.branch_id', $allowedBranches))
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('stock_reservations.branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn ($q) => $q->where('stock_reservations.warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['customer_id']), fn ($q) => $q->where('sales_orders.customer_id', $filters['customer_id']))
            ->when(!empty($filters['product_id']), fn ($q) => $q->where('stock_reservations.product_id', $filters['product_id']))
            ->when(!empty($filters['product_variant_id']), fn ($q) => $q->where('stock_reservations.product_variant_id', $filters['product_variant_id']))
            ->when(!empty($filters['batch_id']), fn ($q) => $q->where('stock_reservations.batch_id', $filters['batch_id']))
            ->when(!empty($filters['created_by']), fn ($q) => $q->where('stock_reservations.created_by', $filters['created_by']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('stock_reservations.created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('stock_reservations.created_at', '<=', $filters['date_to']))
            ->when(!empty($filters['source_type']) && $filters['source_type'] === 'sales_order', fn ($q) => $q->where('stock_reservations.reference_type', SalesOrder::class))
            ->when(!empty($filters['dispatch_status']), fn ($q) => $q->where('sales_orders.dispatch_status', $filters['dispatch_status']))
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $term = '%' . $filters['search'] . '%';
                $q->where(fn ($s) => $s
                    ->where('sales_orders.order_number', 'like', $term)
                    ->orWhere('customers.customer_name', 'like', $term)
                    ->orWhere('customers.customer_code', 'like', $term)
                    ->orWhere('customers.mobile', 'like', $term)
                    ->orWhere('products.name', 'like', $term)
                    ->orWhere('products.sku', 'like', $term)
                    ->orWhere('products.barcode', 'like', $term)
                    ->orWhere('products.primary_barcode', 'like', $term)
                    ->orWhere('product_variant_items.sku', 'like', $term)
                    ->orWhere('product_variant_items.barcode', 'like', $term)
                    ->orWhere('product_batches.batch_no', 'like', $term)
                    ->orWhere('product_batches.batch_number', 'like', $term)
                    ->orWhere('warehouses.name', 'like', $term));
            });
    }

    private function physicalStock(array $scope): float
    {
        return (float) DB::table('stock_ledgers')
            ->where('business_id', $scope['business_id'])
            ->where('product_id', $scope['product_id'])
            ->when($scope['branch_id'], fn ($q) => $q->where('branch_id', $scope['branch_id']))
            ->when($scope['warehouse_id'], fn ($q) => $q->where('warehouse_id', $scope['warehouse_id']))
            ->when($scope['product_variant_id'], fn ($q) => $q->where('product_variant_id', $scope['product_variant_id']))
            ->when($scope['batch_id'], fn ($q) => $q->where('batch_id', $scope['batch_id']))
            ->selectRaw('COALESCE(SUM(quantity_in), 0) - COALESCE(SUM(quantity_out), 0) as quantity')
            ->value('quantity');
    }

    private function activeReservedQuantity(array $scope, $currentReservation = null): float
    {
        return (float) DB::table('stock_reservations')
            ->where('business_id', $scope['business_id'])
            ->where('status', 'active')
            ->where('product_id', $scope['product_id'])
            ->when($scope['branch_id'], fn ($q) => $q->where('branch_id', $scope['branch_id']))
            ->when($scope['warehouse_id'], fn ($q) => $q->where('warehouse_id', $scope['warehouse_id']))
            ->when($scope['product_variant_id'], fn ($q) => $q->where('product_variant_id', $scope['product_variant_id']))
            ->when($scope['batch_id'], fn ($q) => $q->where('batch_id', $scope['batch_id']))
            ->when($currentReservation, fn ($q) => $q->where('reference_id', '!=', $currentReservation))
            ->selectRaw('COALESCE(SUM(reserved_quantity - fulfilled_quantity - released_quantity), 0) as quantity')
            ->value('quantity');
    }

    private function orderForReservation(int $reservation): SalesOrder
    {
        return SalesOrder::query()
            ->where('business_id', AppController::businessId())
            ->when($this->allowedBranchIds(), fn ($q) => $q->whereIn('branch_id', $this->allowedBranchIds()))
            ->where('id', $reservation)
            ->firstOrFail();
    }

    private function allowedId($id, string $type): ?int
    {
        if (!$id) {
            return null;
        }

        $id = (int) $id;
        if ($type === 'branch' && $this->allowedBranchIds() && !in_array($id, $this->allowedBranchIds(), true)) {
            abort(403, 'Selected branch is not assigned to this user.');
        }

        if ($type === 'warehouse') {
            $exists = DB::table('warehouses')
                ->where('business_id', AppController::businessId())
                ->where('id', $id)
                ->when($this->allowedBranchIds(), fn ($q) => $q->whereIn('branch_id', $this->allowedBranchIds()))
                ->exists();
            abort_unless($exists, 403, 'Selected warehouse is not assigned to this user.');
        }

        return $id;
    }

    private function allowedBranchIds(): array
    {
        $user = Auth::user();
        if (!$user || (int) ($user->role_id ?? 2) !== 3 || empty($user->branch_id)) {
            return [];
        }

        return [(int) $user->branch_id];
    }

    private function permissions(): array
    {
        $admin = AppController::roleId() !== 3;

        return [
            'view' => AppController::canOpen('inventory-reserved'),
            'create' => $admin && AppController::canOpen('inventory-reserved'),
            'update' => $admin && AppController::canOpen('inventory-reserved'),
            'confirm' => $admin && AppController::canOpen('inventory-reserved'),
            'release' => $admin && AppController::canOpen('inventory-reserved'),
            'extend' => $admin && AppController::canOpen('inventory-reserved'),
            'cancel' => $admin && AppController::canOpen('inventory-reserved'),
            'dispatch' => $admin && AppController::canOpen('inventory-outward'),
            'export' => $admin && (AppController::canOpen('inventory-reserved') || AppController::canOpen('reports')),
            'print' => AppController::canOpen('inventory-reserved'),
            'view_value' => $admin,
        ];
    }

    private function emptyPage(array $filters): LengthAwarePaginator
    {
        return DB::query()->fromRaw('(select 1 as id) as empty_rows')->whereRaw('1 = 0')->paginate($filters['per_page']);
    }
}
