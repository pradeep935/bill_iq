<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\DeliveryChallan;
use App\Models\SalesOrder;
use App\Models\SalesVoucher;
use App\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StockOutwardService
{
    private const TABS = ['outward', 'reserved', 'ledger'];
    private const PER_PAGE = [10, 25, 50, 100];
    private const OUTWARD_SORTS = ['number', 'date', 'customer', 'warehouse', 'status', 'reference_number', 'created_at'];
    private const LEDGER_SORTS = ['date', 'product', 'quantity', 'value', 'transaction_type'];

    public function payload(array $filters): array
    {
        $filters = $this->filters($filters);

        return [
            'filters' => $filters,
            'references' => $this->references(),
            'summary' => $this->summary($filters),
            'rows' => [
                'outward' => $filters['tab'] === 'outward' ? $this->outward($filters) : $this->emptyPage($filters),
                'reserved' => $filters['tab'] === 'reserved' ? $this->reserved($filters) : $this->emptyPage($filters),
                'ledger' => $filters['tab'] === 'ledger' ? $this->ledger($filters) : $this->emptyPage($filters),
            ],
            'permissions' => $this->permissions(),
            'stock_rule' => 'Sales invoice posting already deducts inventory through stock_ledgers.transaction_type = sale. Dispatch must not deduct invoice stock again.',
        ];
    }

    public function outward(array $filters): LengthAwarePaginator
    {
        $filters = $this->filters($filters);
        $query = $this->outwardQuery($filters);
        $sort = in_array($filters['sort'], self::OUTWARD_SORTS, true) ? $filters['sort'] : 'date';
        $column = [
            'number' => 'number',
            'date' => 'document_date',
            'customer' => 'customer_name',
            'warehouse' => 'warehouse_name',
            'status' => 'dispatch_status',
            'reference_number' => 'reference_number',
            'created_at' => 'created_at',
        ][$sort];

        return $query
            ->orderBy($column, $filters['direction'])
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    public function reserved(array $filters): LengthAwarePaginator
    {
        $filters = $this->filters($filters);

        return $this->reservedQuery($filters)
            ->orderBy('reserved_date', $filters['direction'])
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    public function ledger(array $filters): LengthAwarePaginator
    {
        $filters = $this->filters($filters);
        $sort = in_array($filters['sort'], self::LEDGER_SORTS, true) ? $filters['sort'] : 'date';
        $column = [
            'date' => 'stock_ledgers.transaction_date',
            'product' => 'products.name',
            'quantity' => 'stock_ledgers.quantity_out',
            'value' => 'stock_ledgers.stock_value',
            'transaction_type' => 'stock_ledgers.transaction_type',
        ][$sort];

        return $this->ledgerQuery($filters)
            ->orderBy($column, $filters['direction'])
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    public function summary(array $filters): array
    {
        $filters = $this->filters($filters);
        $period = $this->periodFilters($filters);

        return [
            'dispatch_docs' => (clone $this->outwardQuery(array_merge($filters, $period, ['type' => 'dispatch'])))->count(),
            'reserved_orders' => (clone $this->reservedQuery(array_merge($filters, $period)))->count(),
            'outward_lines' => (clone $this->ledgerQuery(array_merge($filters, $period)))->count(),
            'pending_dispatch' => (clone $this->outwardQuery(array_merge($filters, $period, ['status' => 'pending'])))->count(),
        ];
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        $allowedBranchIds = $this->allowedBranchIds();

        return [
            'tabs' => self::TABS,
            'per_page' => self::PER_PAGE,
            'statuses' => ['pending', 'draft', 'dispatched', 'delivered', 'partial', 'completed'],
            'reference_types' => ['sales_invoice', 'sales_order', 'delivery_challan', 'stock_transfer', 'manual_outward'],
            'branches' => Schema::hasTable('branches')
                ? DB::table('branches')->where('business_id', $businessId)->when($allowedBranchIds, fn ($q) => $q->whereIn('id', $allowedBranchIds))->orderBy('name')->get(['id', 'name', 'code'])
                : collect(),
            'warehouses' => Schema::hasTable('warehouses')
                ? DB::table('warehouses')->where('business_id', $businessId)->when($allowedBranchIds, fn ($q) => $q->whereIn('branch_id', $allowedBranchIds))->orderBy('name')->get(['id', 'branch_id', 'name', 'code'])
                : collect(),
        ];
    }

    public function export(array $filters): array
    {
        $filters = $this->filters($filters);
        $tab = $filters['tab'];
        $rows = match ($tab) {
            'reserved' => $this->reservedQuery($filters)->orderBy('reserved_date')->limit(5000)->get(),
            'ledger' => $this->ledgerQuery($filters)->orderBy('stock_ledgers.transaction_date')->limit(5000)->get(),
            default => $this->outwardQuery($filters)->orderBy('document_date')->limit(5000)->get(),
        };

        $headers = match ($tab) {
            'reserved' => ['Reservation number', 'Source type', 'Source number', 'Customer', 'Warehouse', 'Reserved quantity', 'Dispatched quantity', 'Remaining quantity', 'Expiry', 'Status'],
            'ledger' => ['Date', 'Product', 'SKU', 'Variant', 'Batch', 'Branch', 'Warehouse', 'Quantity out', 'Unit cost', 'Value', 'Transaction type', 'Reference'],
            default => ['Outward number', 'Date', 'Reference type', 'Reference number', 'Customer', 'Branch', 'Warehouse', 'Product lines', 'Total quantity', 'Status', 'Created by'],
        };

        $mapped = $rows->map(function ($row) use ($tab) {
            if ($tab === 'reserved') {
                return [$row->reservation_number, $row->source_type, $row->source_number, $row->customer_name, $row->warehouse_name, $row->reserved_quantity, $row->dispatched_quantity, $row->remaining_quantity, $row->expiry, $row->status];
            }
            if ($tab === 'ledger') {
                return [$row->date, $row->product_name, $row->sku, $row->variant_name, $row->batch_number, $row->branch_name, $row->warehouse_name, $row->quantity_out, $row->unit_cost, $row->value, $row->transaction_type, trim($row->reference_type . ' ' . $row->reference_number)];
            }

            return [$row->number, $row->date, $row->reference_type, $row->reference_number, $row->customer_name, $row->branch_name, $row->warehouse_name, $row->total_lines, $row->total_quantity, $row->dispatch_status, $row->created_by_name];
        })->all();

        return [
            'filename' => match ($tab) {
                'reserved' => 'reserved-stock-' . now()->toDateString() . '.csv',
                'ledger' => 'stock-outward-ledger-' . now()->toDateString() . '.csv',
                default => 'stock-outward-' . now()->toDateString() . '.csv',
            },
            'headers' => $headers,
            'rows' => $mapped,
        ];
    }

    public function releaseReservation(int $orderId, ?string $reason = null): void
    {
        abort_unless($this->permissions()['release'], 403);
        app(OrderManagementService::class)->releaseSalesOrderReservation($orderId, $reason);
        AuditLogger::record([
            'module_name' => 'stock_outward',
            'record_id' => $orderId,
            'action_type' => 'Reservation Released',
            'changes' => [['field_name' => 'reason', 'old_value' => null, 'new_value' => $reason]],
        ]);
    }

    public function dispatchChallan(int $challanId): DeliveryChallan
    {
        abort_unless($this->permissions()['dispatch'], 403);
        $challan = DeliveryChallan::query()
            ->where('business_id', AppController::businessId())
            ->when($this->allowedBranchIds(), fn ($q) => $q->whereIn('branch_id', $this->allowedBranchIds()))
            ->findOrFail($challanId);

        $posted = app(OrderManagementService::class)->dispatchChallan($challan->id);
        AuditLogger::record([
            'module_name' => 'stock_outward',
            'record_id' => $posted->id,
            'action_type' => 'Stock Outward Posted',
            'changes' => [['field_name' => 'status', 'old_value' => $challan->status, 'new_value' => $posted->status]],
        ]);

        return $posted;
    }

    public function printHtml(array $filters): string
    {
        $filters = $this->filters($filters);
        $data = match ($filters['tab']) {
            'reserved' => $this->reservedQuery($filters)->orderBy('reserved_date')->limit(500)->get(),
            'ledger' => $this->ledgerQuery($filters)->orderBy('stock_ledgers.transaction_date')->limit(500)->get(),
            default => $this->outwardQuery($filters)->orderBy('document_date')->limit(500)->get(),
        };
        $title = match ($filters['tab']) {
            'reserved' => 'Reserved Stock Report',
            'ledger' => 'Stock Outward Ledger Report',
            default => 'Stock Outward Report',
        };
        $rows = $data->map(fn ($row) => '<tr><td>' . e($row->date ?? $row->reserved_date) . '</td><td>' . e($row->number ?? $row->reservation_number ?? $row->reference_number) . '</td><td>' . e($row->customer_name ?? $row->product_name ?? '-') . '</td><td>' . e($row->warehouse_name ?? '-') . '</td><td>' . e($row->dispatch_status ?? $row->status ?? $row->transaction_type) . '</td></tr>')->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title><style>body{font-family:Arial,sans-serif;color:#111;margin:24px}.muted{color:#667085}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f8fafc}.print{margin-top:20px}@media print{.print{display:none}}</style></head><body><h1>' . e($title) . '</h1><p class="muted">Generated ' . e(now()->format('Y-m-d H:i')) . ' by ' . e(Auth::user()->name ?? 'System') . '</p><p class="muted">Date range: ' . e($filters['date_from'] ?: 'All') . ' to ' . e($filters['date_to'] ?: 'All') . '</p><table><thead><tr><th>Date</th><th>Number / Reference</th><th>Customer / Product</th><th>Warehouse</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table><button class="print" onclick="window.print()">Print</button></body></html>';
    }

    public function filters(array $filters): array
    {
        $tab = in_array(($filters['tab'] ?? 'outward'), self::TABS, true) ? $filters['tab'] : 'outward';
        $perPage = in_array((int) ($filters['per_page'] ?? 25), self::PER_PAGE, true) ? (int) ($filters['per_page'] ?? 25) : 25;
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $normalized = [
            'tab' => $tab,
            'search' => trim((string) ($filters['search'] ?? $filters['q'] ?? '')),
            'per_page' => $perPage,
            'page' => max(1, (int) ($filters['page'] ?? 1)),
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'branch_id' => $this->allowedId($filters['branch_id'] ?? null, 'branch'),
            'warehouse_id' => $this->allowedId($filters['warehouse_id'] ?? null, 'warehouse'),
            'customer_id' => $filters['customer_id'] ?? null,
            'created_by' => $filters['created_by'] ?? null,
            'status' => $filters['status'] ?? null,
            'reference_type' => $filters['reference_type'] ?? null,
            'type' => $filters['type'] ?? null,
            'movement' => $filters['movement'] ?? null,
            'sort' => $filters['sort'] ?? 'date',
            'direction' => $direction,
        ];

        if ($normalized['warehouse_id'] && $normalized['branch_id']) {
            $warehouseBranch = DB::table('warehouses')->where('id', $normalized['warehouse_id'])->value('branch_id');
            abort_if($warehouseBranch && (int) $warehouseBranch !== (int) $normalized['branch_id'], 403, 'Selected warehouse is not assigned to this branch.');
        }

        return $normalized;
    }

    private function outwardQuery(array $filters): Builder
    {
        $businessId = AppController::businessId();
        $challans = DB::table('delivery_challans')
            ->leftJoin('customers', 'customers.id', '=', 'delivery_challans.customer_id')
            ->leftJoin('branches', 'branches.id', '=', 'delivery_challans.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'delivery_challans.warehouse_id')
            ->leftJoin('sales_orders', 'sales_orders.id', '=', 'delivery_challans.sales_order_id')
            ->leftJoin('users', 'users.id', '=', 'delivery_challans.created_by')
            ->leftJoin('delivery_challan_items', 'delivery_challan_items.delivery_challan_id', '=', 'delivery_challans.id')
            ->where('delivery_challans.business_id', $businessId)
            ->selectRaw("'challan' as row_type, delivery_challans.id, delivery_challans.branch_id, delivery_challans.warehouse_id, delivery_challans.customer_id, delivery_challans.created_by, delivery_challans.challan_number as number, delivery_challans.challan_date as date, delivery_challans.challan_date as document_date, 'Delivery Challan' as reference_type, COALESCE(sales_orders.order_number, delivery_challans.dispatch_reference) as reference_number, customers.customer_name, customers.mobile, branches.name as branch_name, warehouses.name as warehouse_name, delivery_challans.status as dispatch_status, CASE WHEN delivery_challans.status IN ('dispatched','delivered') THEN 'posted' ELSE 'pending' END as stock_status, users.name as created_by_name, delivery_challans.created_at, COUNT(delivery_challan_items.id) as total_lines, COALESCE(SUM(delivery_challan_items.dispatch_quantity), 0) as total_quantity")
            ->groupBy('delivery_challans.id', 'delivery_challans.branch_id', 'delivery_challans.warehouse_id', 'delivery_challans.customer_id', 'delivery_challans.created_by', 'delivery_challans.challan_number', 'delivery_challans.challan_date', 'sales_orders.order_number', 'delivery_challans.dispatch_reference', 'customers.customer_name', 'customers.mobile', 'branches.name', 'warehouses.name', 'delivery_challans.status', 'users.name', 'delivery_challans.created_at');

        $invoices = DB::table('sales_vouchers')
            ->leftJoin('customers', 'customers.id', '=', 'sales_vouchers.customer_id')
            ->leftJoin('branches', 'branches.id', '=', 'sales_vouchers.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'sales_vouchers.warehouse_id')
            ->leftJoin('users', 'users.id', '=', 'sales_vouchers.created_by')
            ->leftJoin('sales_items', 'sales_items.sales_voucher_id', '=', 'sales_vouchers.id')
            ->where('sales_vouchers.business_id', $businessId)
            ->whereIn('sales_vouchers.status', ['confirmed', 'approved'])
            ->selectRaw("'invoice' as row_type, sales_vouchers.id, sales_vouchers.branch_id, sales_vouchers.warehouse_id, sales_vouchers.customer_id, sales_vouchers.created_by, sales_vouchers.invoice_number as number, sales_vouchers.invoice_date as date, sales_vouchers.invoice_date as document_date, 'Sales Invoice' as reference_type, sales_vouchers.invoice_number as reference_number, customers.customer_name, customers.mobile, branches.name as branch_name, warehouses.name as warehouse_name, 'dispatched' as dispatch_status, 'posted' as stock_status, users.name as created_by_name, sales_vouchers.created_at, COUNT(sales_items.id) as total_lines, COALESCE(SUM(sales_items.quantity + COALESCE(sales_items.free_quantity, 0)), 0) as total_quantity")
            ->groupBy('sales_vouchers.id', 'sales_vouchers.branch_id', 'sales_vouchers.warehouse_id', 'sales_vouchers.customer_id', 'sales_vouchers.created_by', 'sales_vouchers.invoice_number', 'sales_vouchers.invoice_date', 'customers.customer_name', 'customers.mobile', 'branches.name', 'warehouses.name', 'users.name', 'sales_vouchers.created_at');

        $query = DB::query()->fromSub($challans->unionAll($invoices), 'outward');
        $this->applyCommon($query, $filters, 'document_date');

        return $query
            ->when(($filters['status'] ?? null) === 'pending', fn ($q) => $q->whereIn('dispatch_status', ['draft', 'ready_to_pick', 'picking', 'packed', 'partial', 'pending']))
            ->when(!empty($filters['status']) && $filters['status'] !== 'pending', fn ($q) => $q->where('dispatch_status', $filters['status']))
            ->when(($filters['type'] ?? null) === 'dispatch', fn ($q) => $q->where('row_type', 'challan'))
            ->when(!empty($filters['reference_type']), fn ($q) => $q->where('reference_type', str_replace('_', ' ', $filters['reference_type'])));
    }

    private function reservedQuery(array $filters): Builder
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
            ->where('stock_reservations.business_id', AppController::businessId())
            ->selectRaw("stock_reservations.reference_id as id, CONCAT('RSV-', stock_reservations.reference_id) as reservation_number, MIN(stock_reservations.created_at) as reserved_date, stock_reservations.reference_type as source_type, COALESCE(sales_orders.order_number, stock_reservations.reference_id) as source_number, customers.customer_name, branches.name as branch_name, warehouses.name as warehouse_name, COUNT(DISTINCT stock_reservations.product_id) as product_lines, COALESCE(SUM(stock_reservations.reserved_quantity),0) as reserved_quantity, COALESCE(SUM(stock_reservations.fulfilled_quantity),0) as dispatched_quantity, COALESCE(SUM(stock_reservations.reserved_quantity - stock_reservations.fulfilled_quantity - stock_reservations.released_quantity),0) as remaining_quantity, MAX(stock_reservations.expires_at) as expiry, CASE WHEN COALESCE(SUM(stock_reservations.released_quantity),0) >= COALESCE(SUM(stock_reservations.reserved_quantity),0) THEN 'released' WHEN COALESCE(SUM(stock_reservations.fulfilled_quantity),0) >= COALESCE(SUM(stock_reservations.reserved_quantity),0) THEN 'fully_dispatched' WHEN COALESCE(SUM(stock_reservations.fulfilled_quantity),0) > 0 THEN 'partially_dispatched' ELSE 'active' END as status")
            ->groupBy('stock_reservations.reference_id', 'stock_reservations.reference_type', 'sales_orders.order_number', 'customers.customer_name', 'branches.name', 'warehouses.name');

        $this->applyCommon($query, $filters, 'stock_reservations.created_at', 'stock_reservations');

        return $query
            ->where('stock_reservations.status', 'active')
            ->having('remaining_quantity', '>', 0)
            ->when(!empty($filters['status']), fn ($q) => $q->having('status', $filters['status']));
    }

    private function ledgerQuery(array $filters): Builder
    {
        $query = DB::table('stock_ledgers')
            ->leftJoin('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->leftJoin('product_variant_items', 'product_variant_items.id', '=', 'stock_ledgers.product_variant_id')
            ->leftJoin('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')
            ->leftJoin('branches', 'branches.id', '=', 'stock_ledgers.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'stock_ledgers.warehouse_id')
            ->leftJoin('users', 'users.id', '=', 'stock_ledgers.created_by')
            ->where('stock_ledgers.business_id', AppController::businessId())
            ->where('stock_ledgers.quantity_out', '>', 0)
            ->selectRaw("stock_ledgers.id, stock_ledgers.transaction_date as date, products.name as product_name, products.sku, product_variant_items.sku as variant_name, COALESCE(product_batches.batch_no, product_batches.batch_number) as batch_number, branches.name as branch_name, warehouses.name as warehouse_name, stock_ledgers.quantity_out, stock_ledgers.unit_cost, stock_ledgers.stock_value as value, stock_ledgers.transaction_type, stock_ledgers.reference_type, stock_ledgers.reference_id, stock_ledgers.reference_id as reference_number, users.name as created_by_name");

        $this->applyCommon($query, $filters, 'stock_ledgers.transaction_date', 'stock_ledgers');

        return $query
            ->when(!empty($filters['reference_type']), fn ($q) => $q->where('stock_ledgers.transaction_type', $filters['reference_type']))
            ->when(!empty($filters['movement']), fn ($q) => $q->where('stock_ledgers.quantity_out', '>', 0));
    }

    private function applyCommon(Builder $query, array $filters, string $dateColumn, ?string $table = null): void
    {
        $prefix = $table ? $table . '.' : '';
        $allowedBranchIds = $this->allowedBranchIds();

        $query
            ->when($allowedBranchIds, fn ($q) => $q->whereIn($prefix . 'branch_id', $allowedBranchIds))
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where($prefix . 'branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn ($q) => $q->where($prefix . 'warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['customer_id']) && !$table, fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(!empty($filters['customer_id']) && $table === 'stock_reservations', fn ($q) => $q->where('sales_orders.customer_id', $filters['customer_id']))
            ->when(!empty($filters['created_by']) && in_array($table, [null, 'stock_reservations', 'stock_ledgers'], true), fn ($q) => $q->where($prefix . 'created_by', $filters['created_by']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate($dateColumn, '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate($dateColumn, '<=', $filters['date_to']))
            ->when(!empty($filters['search']), function ($q) use ($filters, $table) {
                $term = '%' . $filters['search'] . '%';
                if ($table === 'stock_ledgers') {
                    $q->where(fn ($s) => $s->where('products.name', 'like', $term)->orWhere('products.sku', 'like', $term)->orWhere('products.barcode', 'like', $term)->orWhere('products.primary_barcode', 'like', $term)->orWhere('product_batches.batch_no', 'like', $term)->orWhere('product_batches.batch_number', 'like', $term)->orWhere('stock_ledgers.transaction_type', 'like', $term)->orWhere('stock_ledgers.reference_id', 'like', $term));
                } elseif ($table === 'stock_reservations') {
                    $q->where(fn ($s) => $s->where('sales_orders.order_number', 'like', $term)->orWhere('customers.customer_name', 'like', $term)->orWhere('customers.customer_code', 'like', $term)->orWhere('customers.mobile', 'like', $term)->orWhere('products.name', 'like', $term)->orWhere('products.sku', 'like', $term));
                } else {
                    $q->where(fn ($s) => $s->where('number', 'like', $term)->orWhere('reference_number', 'like', $term)->orWhere('customer_name', 'like', $term)->orWhere('mobile', 'like', $term));
                }
            });
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
            'view' => AppController::canOpen('inventory-outward') || AppController::canOpen('inventory-reserved') || AppController::canOpen('stock-ledger'),
            'export' => $admin && (AppController::canOpen('inventory-outward') || AppController::canOpen('reports')),
            'dispatch' => $admin && AppController::canOpen('inventory-outward'),
            'release' => $admin && AppController::canOpen('inventory-reserved'),
            'print' => AppController::canOpen('inventory-outward') || AppController::canOpen('stock-ledger'),
            'view_cost' => $admin,
        ];
    }

    private function periodFilters(array $filters): array
    {
        return ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']];
    }

    private function emptyPage(array $filters): LengthAwarePaginator
    {
        return DB::query()->fromRaw('(select 1 as id) as empty')->whereRaw('1 = 0')->paginate($filters['per_page']);
    }
}
