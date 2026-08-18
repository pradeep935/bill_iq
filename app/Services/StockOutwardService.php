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
    private const TABS = ['sale_dispatch', 'manual_outward', 'ledger'];
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
                'sale_dispatch' => $filters['tab'] === 'sale_dispatch' ? $this->outward(array_merge($filters, ['row_type' => 'invoice', 'status' => $filters['status'] ?: null])) : $this->emptyPage($filters),
                'manual_outward' => $filters['tab'] === 'manual_outward' ? $this->outward(array_merge($filters, ['row_type' => 'challan', 'status' => $filters['status'] ?: null])) : $this->emptyPage($filters),
                'reserved' => $filters['tab'] === 'reserved' ? $this->reserved($filters) : $this->emptyPage($filters),
                'ledger' => $filters['tab'] === 'ledger' ? $this->ledger($filters) : $this->emptyPage($filters),
            ],
            'permissions' => $this->permissions(),
            'stock_rule' => 'Sale-linked dispatch is physical handling only. Posted sales invoices already deduct stock through stock_ledgers.transaction_type = sale, so Stock Outward never deducts those invoice quantities again.',
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
            'sale_linked_dispatch' => (clone $this->outwardQuery(array_merge($filters, $period, ['row_type' => 'invoice'])))->count(),
            'manual_outward' => (clone $this->outwardQuery(array_merge($filters, $period, ['row_type' => 'challan'])))->count(),
            'reserved_orders' => (clone $this->reservedQuery(array_merge($filters, $period)))->count(),
            'ready_for_dispatch' => (clone $this->outwardQuery(array_merge($filters, $period, ['row_type' => 'challan', 'status' => 'ready'])))->count(),
            'pending_dispatch' => (clone $this->outwardQuery(array_merge($filters, $period, ['row_type' => 'challan', 'status' => 'pending'])))->count(),
            'completed_dispatch' => (clone $this->outwardQuery(array_merge($filters, $period, ['status' => 'delivered'])))->count(),
            'stock_posted_manual' => (clone $this->outwardQuery(array_merge($filters, $period, ['row_type' => 'challan', 'status' => 'dispatched'])))->count(),
            'outward_lines' => (clone $this->ledgerQuery(array_merge($filters, $period)))->count(),
        ];
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        $allowedBranchIds = $this->allowedBranchIds();

        return [
            'tabs' => self::TABS,
            'per_page' => self::PER_PAGE,
            'statuses' => ['draft', 'ready', 'picking', 'packed', 'dispatched', 'delivered', 'cancelled', 'pending'],
            'delivery_statuses' => ['pending', 'in_transit', 'delivered', 'cancelled'],
            'reference_types' => ['sales_invoice', 'sales_order', 'delivery_challan', 'manual_outward'],
            'customers' => Schema::hasTable('customers')
                ? DB::table('customers')->where('business_id', $businessId)->orderBy('customer_name')->limit(500)->get(['id', 'customer_name', 'customer_code', 'mobile'])
                : collect(),
            'transporters' => Schema::hasTable('delivery_challans')
                ? DB::table('delivery_challans')->where('business_id', $businessId)->whereNotNull('transporter_name')->distinct()->orderBy('transporter_name')->pluck('transporter_name')
                : collect(),
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
        if ($tab === 'reserved') {
            $rows = $this->reservedQuery($filters)->orderBy('reserved_date')->limit(5000)->get();
            $headers = ['Reservation number', 'Source type', 'Source number', 'Customer', 'Warehouse', 'Reserved quantity', 'Dispatched quantity', 'Remaining quantity', 'Expiry', 'Status'];
        } elseif ($tab === 'ledger') {
            $rows = $this->ledgerQuery($filters)->orderBy('stock_ledgers.transaction_date')->limit(5000)->get();
            $headers = ['Date', 'Product', 'SKU', 'Variant', 'Batch', 'Branch', 'Warehouse', 'Quantity out', 'Unit cost', 'Value', 'Transaction type', 'Reference'];
        } else {
            $rows = $this->outwardQuery($filters)->orderBy('document_date')->limit(5000)->get();
            $headers = ['Outward number', 'Date', 'Reference type', 'Reference number', 'Customer', 'Branch', 'Warehouse', 'Product lines', 'Total quantity', 'Status', 'Created by'];
        }

        $mapped = $rows->map(function ($row) use ($tab) {
            if ($tab === 'reserved') {
                return [$row->reservation_number, $row->source_type, $row->source_number, $row->customer_name, $row->warehouse_name, $row->reserved_quantity, $row->consumed_quantity, $row->remaining_quantity, $row->expiry, $row->status];
            }
            if ($tab === 'ledger') {
                return [$row->date, $row->product_name, $row->sku, $row->variant_name, $row->batch_number, $row->branch_name, $row->warehouse_name, $row->quantity_out, $row->unit_cost, $row->value, $row->transaction_type, trim($row->reference_type . ' ' . $row->reference_number)];
            }

            return [$row->number, $row->date, $row->reference_type, $row->reference_number, $row->customer_name, $row->branch_name, $row->warehouse_name, $row->total_lines, $row->total_quantity, $row->dispatch_status, $row->created_by_name];
        })->all();

        return [
            'filename' => ($tab === 'reserved' ? 'reserved-stock-' : ($tab === 'ledger' ? 'stock-outward-ledger-' : 'stock-outward-')) . now()->toDateString() . '.csv',
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
        $this->assertChallanIsNotSaleLinked($challan);

        if (!in_array($challan->status, ['packed'], true)) {
            throw ValidationException::withMessages(['status' => 'Only packed manual outward documents can be dispatched.']);
        }

        $posted = app(OrderManagementService::class)->dispatchChallan($challan->id);
        AuditLogger::record([
            'module_name' => 'stock_outward',
            'record_id' => $posted->id,
            'action_type' => 'Stock Outward Posted',
            'changes' => [['field_name' => 'status', 'old_value' => $challan->status, 'new_value' => $posted->status]],
        ]);

        return $posted;
    }

    public function workflow(int $challanId, string $action, array $data = []): DeliveryChallan
    {
        abort_unless($this->permissions()['dispatch'], 403);

        if ($action === 'dispatch') {
            return $this->dispatchChallan($challanId);
        }

        $challan = DeliveryChallan::query()
            ->where('business_id', AppController::businessId())
            ->when($this->allowedBranchIds(), fn ($q) => $q->whereIn('branch_id', $this->allowedBranchIds()))
            ->findOrFail($challanId);

        if ($action === 'cancel') {
            if (in_array($challan->status, ['dispatched', 'delivered'], true)) {
                throw ValidationException::withMessages(['status' => 'Posted dispatch cannot be cancelled from Stock Outward. Use a reversal workflow.']);
            }
            $challan->update(['status' => 'cancelled', 'remarks' => trim(($challan->remarks ? $challan->remarks . "\n" : '') . ($data['reason'] ?? 'Dispatch cancelled'))]);
            return $challan->fresh(['items.product', 'customer', 'warehouse', 'order']);
        }

        $target = [
            'pick' => 'picking',
            'pack' => 'packed',
            'deliver' => 'delivered',
        ][$action] ?? null;

        if (!$target) {
            throw ValidationException::withMessages(['action' => 'Unknown dispatch action.']);
        }

        $allowedTransitions = [
            'pick' => ['draft', 'ready', 'ready_to_pick', 'pending'],
            'pack' => ['picking'],
            'deliver' => ['dispatched'],
        ];

        if (!in_array($challan->status, $allowedTransitions[$action] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid dispatch status transition.']);
        }

        if ($target === 'delivered' && !in_array($challan->status, ['dispatched', 'delivered'], true)) {
            throw ValidationException::withMessages(['status' => 'Only dispatched challans can be marked delivered.']);
        }

        $payload = ['status' => $target];
        if ($target === 'delivered') {
            $payload['delivered_by'] = Auth::id();
            $payload['delivered_at'] = now();
        }

        $challan->update($payload);

        AuditLogger::record([
            'module_name' => 'stock_outward',
            'record_id' => $challan->id,
            'action_type' => 'Dispatch ' . ucfirst($action),
            'changes' => [['field_name' => 'status', 'old_value' => $challan->getOriginal('status'), 'new_value' => $target]],
        ]);

        return $challan->fresh(['items.product', 'customer', 'warehouse', 'order']);
    }

    public function detail(int $id, string $rowType = 'challan'): array
    {
        if ($rowType === 'invoice') {
            $voucher = SalesVoucher::query()
                ->with(['customer', 'branch', 'warehouse', 'items.product', 'items.batch'])
                ->where('business_id', AppController::businessId())
                ->when($this->allowedBranchIds(), fn ($q) => $q->whereIn('branch_id', $this->allowedBranchIds()))
                ->findOrFail($id);

            return [
                'id' => $voucher->id,
                'row_type' => 'invoice',
                'number' => $voucher->invoice_number,
                'invoice_number' => $voucher->invoice_number,
                'order_number' => null,
                'customer' => optional($voucher->customer)->customer_name ?: $voucher->customer_name_snapshot,
                'mobile' => optional($voucher->customer)->mobile ?: $voucher->customer_mobile_snapshot,
                'delivery_address' => optional($voucher->customer)->shipping_address ?: optional($voucher->customer)->billing_address,
                'branch' => optional($voucher->branch)->name,
                'warehouse' => optional($voucher->warehouse)->name,
                'date' => optional($voucher->invoice_date)->format('Y-m-d'),
                'dispatch_status' => 'dispatched',
                'delivery_status' => 'in_transit',
                'transporter' => null,
                'vehicle_number' => null,
                'driver_name' => null,
                'driver_mobile' => null,
                'lr_number' => null,
                'e_way_bill_number' => null,
                'items' => $voucher->items->map(fn ($item) => [
                    'product' => $item->product_name_snapshot,
                    'sku' => $item->sku_snapshot,
                    'batch' => optional($item->batch)->batch_no ?: optional($item->batch)->batch_number,
                    'quantity' => (float) $item->quantity + (float) $item->free_quantity,
                    'picked_quantity' => (float) $item->quantity + (float) $item->free_quantity,
                    'packed_quantity' => (float) $item->quantity + (float) $item->free_quantity,
                    'remaining_quantity' => 0,
                ])->values(),
            ];
        }

        $challan = DeliveryChallan::query()
            ->with(['customer', 'branch', 'warehouse', 'order', 'items.product'])
            ->where('business_id', AppController::businessId())
            ->when($this->allowedBranchIds(), fn ($q) => $q->whereIn('branch_id', $this->allowedBranchIds()))
            ->findOrFail($id);

        $batchIds = $challan->items->pluck('batch_id')->filter()->unique()->values();
        $batches = $batchIds->isEmpty() ? collect() : DB::table('product_batches')->whereIn('id', $batchIds)->get()->keyBy('id');

        return [
            'id' => $challan->id,
            'row_type' => 'challan',
            'number' => $challan->challan_number,
            'invoice_number' => null,
            'order_number' => optional($challan->order)->order_number ?: $challan->dispatch_reference,
            'customer' => optional($challan->customer)->customer_name,
            'mobile' => optional($challan->customer)->mobile,
            'delivery_address' => optional($challan->customer)->shipping_address ?: optional($challan->customer)->billing_address,
            'branch' => optional($challan->branch)->name,
            'warehouse' => optional($challan->warehouse)->name,
            'date' => optional($challan->challan_date)->format('Y-m-d'),
            'dispatch_status' => $challan->status,
            'delivery_status' => $challan->status === 'delivered' ? 'delivered' : ($challan->status === 'dispatched' ? 'in_transit' : 'pending'),
            'transporter' => $challan->transporter_name,
            'vehicle_number' => $challan->vehicle_number,
            'driver_name' => null,
            'driver_mobile' => null,
            'lr_number' => $challan->tracking_number,
            'e_way_bill_number' => null,
            'items' => $challan->items->map(function ($item) use ($batches, $challan) {
                $quantity = (float) $item->dispatch_quantity;
                $picked = in_array($challan->status, ['picking', 'packed', 'dispatched', 'delivered'], true) ? $quantity : 0;
                $packed = in_array($challan->status, ['packed', 'dispatched', 'delivered'], true) ? $quantity : 0;
                $batch = $item->batch_id ? $batches->get($item->batch_id) : null;
                return [
                    'product' => optional($item->product)->name,
                    'sku' => optional($item->product)->sku,
                    'batch' => $batch ? ($batch->batch_no ?: $batch->batch_number) : null,
                    'quantity' => $quantity,
                    'picked_quantity' => $picked,
                    'packed_quantity' => $packed,
                    'remaining_quantity' => max(0, $quantity - $packed),
                ];
            })->values(),
        ];
    }

    public function printHtml(array $filters): string
    {
        $filters = $this->filters($filters);
        if ($filters['tab'] === 'reserved') {
            $data = $this->reservedQuery($filters)->orderBy('reserved_date')->limit(500)->get();
            $title = 'Reserved Stock Report';
        } elseif ($filters['tab'] === 'ledger') {
            $data = $this->ledgerQuery($filters)->orderBy('stock_ledgers.transaction_date')->limit(500)->get();
            $title = 'Stock Outward Ledger Report';
        } else {
            $data = $this->outwardQuery($filters)->orderBy('document_date')->limit(500)->get();
            $title = 'Stock Outward Report';
        }
        $rows = $data->map(fn ($row) => '<tr><td>' . e($row->date ?? $row->reserved_date) . '</td><td>' . e($row->number ?? $row->reservation_number ?? $row->reference_number) . '</td><td>' . e($row->customer_name ?? $row->product_name ?? '-') . '</td><td>' . e($row->warehouse_name ?? '-') . '</td><td>' . e($row->dispatch_status ?? $row->status ?? $row->transaction_type) . '</td></tr>')->implode('');

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title><style>body{font-family:Arial,sans-serif;color:#111;margin:24px}.muted{color:#667085}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f8fafc}.print{margin-top:20px}@media print{.print{display:none}}</style></head><body><h1>' . e($title) . '</h1><p class="muted">Generated ' . e(now()->format('Y-m-d H:i')) . ' by ' . e(Auth::user()->name ?? 'System') . '</p><p class="muted">Date range: ' . e($filters['date_from'] ?: 'All') . ' to ' . e($filters['date_to'] ?: 'All') . '</p><table><thead><tr><th>Date</th><th>Number / Reference</th><th>Customer / Product</th><th>Warehouse</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table><button class="print" onclick="window.print()">Print</button></body></html>';
    }

    public function filters(array $filters): array
    {
        $requestedTab = $filters['tab'] ?? 'sale_dispatch';
        $tab = in_array($requestedTab, self::TABS, true) ? $requestedTab : 'sale_dispatch';
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
            'delivery_status' => $filters['delivery_status'] ?? null,
            'reference_type' => $filters['reference_type'] ?? null,
            'sales_invoice' => trim((string) ($filters['sales_invoice'] ?? '')),
            'order_number' => trim((string) ($filters['order_number'] ?? '')),
            'transporter' => trim((string) ($filters['transporter'] ?? '')),
            'vehicle_number' => trim((string) ($filters['vehicle_number'] ?? '')),
            'type' => $filters['type'] ?? null,
            'row_type' => $filters['row_type'] ?? null,
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
            ->selectRaw("'challan' as row_type, 'Manual Outward' as source_type, delivery_challans.id, delivery_challans.branch_id, delivery_challans.warehouse_id, delivery_challans.customer_id, delivery_challans.created_by, delivery_challans.challan_number as number, delivery_challans.challan_number as challan_number, NULL as sales_invoice, delivery_challans.challan_date as date, delivery_challans.challan_date as document_date, 'Delivery Challan' as reference_type, COALESCE(sales_orders.order_number, delivery_challans.dispatch_reference) as reference_number, COALESCE(sales_orders.order_number, delivery_challans.dispatch_reference) as order_number, customers.customer_name, customers.mobile, branches.name as branch_name, warehouses.name as warehouse_name, delivery_challans.status as dispatch_status, CASE WHEN delivery_challans.status = 'delivered' THEN 'delivered' WHEN delivery_challans.status IN ('dispatched') THEN 'in_transit' WHEN delivery_challans.status = 'cancelled' THEN 'cancelled' ELSE 'pending' END as delivery_status, delivery_challans.transporter_name as transporter, delivery_challans.vehicle_number, NULL as driver_name, NULL as driver_mobile, delivery_challans.tracking_number as lr_number, NULL as e_way_bill_number, CASE WHEN delivery_challans.status IN ('dispatched','delivered') THEN 'posted_by_outward' ELSE 'pending_stock_post' END as stock_status, 'Deducts stock on dispatch' as stock_policy, users.name as created_by_name, delivery_challans.created_at, COUNT(delivery_challan_items.id) as total_lines, COALESCE(SUM(delivery_challan_items.dispatch_quantity), 0) as total_quantity")
            ->groupBy('delivery_challans.id', 'delivery_challans.branch_id', 'delivery_challans.warehouse_id', 'delivery_challans.customer_id', 'delivery_challans.created_by', 'delivery_challans.challan_number', 'delivery_challans.challan_date', 'sales_orders.order_number', 'delivery_challans.dispatch_reference', 'customers.customer_name', 'customers.mobile', 'branches.name', 'warehouses.name', 'delivery_challans.status', 'delivery_challans.transporter_name', 'delivery_challans.vehicle_number', 'delivery_challans.tracking_number', 'users.name', 'delivery_challans.created_at');
        AppController::applyTenantScope($challans, 'delivery_challans');

        $invoices = DB::table('sales_vouchers')
            ->leftJoin('customers', 'customers.id', '=', 'sales_vouchers.customer_id')
            ->leftJoin('branches', 'branches.id', '=', 'sales_vouchers.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'sales_vouchers.warehouse_id')
            ->leftJoin('users', 'users.id', '=', 'sales_vouchers.created_by')
            ->leftJoin('sales_items', 'sales_items.sales_voucher_id', '=', 'sales_vouchers.id')
            ->where('sales_vouchers.business_id', $businessId)
            ->whereIn('sales_vouchers.status', ['confirmed', 'approved'])
            ->selectRaw("'invoice' as row_type, 'Sale-Linked Dispatch' as source_type, sales_vouchers.id, sales_vouchers.branch_id, sales_vouchers.warehouse_id, sales_vouchers.customer_id, sales_vouchers.created_by, sales_vouchers.invoice_number as number, NULL as challan_number, sales_vouchers.invoice_number as sales_invoice, sales_vouchers.invoice_date as date, sales_vouchers.invoice_date as document_date, 'Sales Invoice' as reference_type, sales_vouchers.invoice_number as reference_number, NULL as order_number, customers.customer_name, customers.mobile, branches.name as branch_name, warehouses.name as warehouse_name, 'invoice_posted' as dispatch_status, 'pending' as delivery_status, NULL as transporter, NULL as vehicle_number, NULL as driver_name, NULL as driver_mobile, NULL as lr_number, NULL as e_way_bill_number, 'posted_by_sale' as stock_status, 'No stock deduction in Stock Outward' as stock_policy, users.name as created_by_name, sales_vouchers.created_at, COUNT(sales_items.id) as total_lines, COALESCE(SUM(sales_items.quantity + COALESCE(sales_items.free_quantity, 0)), 0) as total_quantity")
            ->groupBy('sales_vouchers.id', 'sales_vouchers.branch_id', 'sales_vouchers.warehouse_id', 'sales_vouchers.customer_id', 'sales_vouchers.created_by', 'sales_vouchers.invoice_number', 'sales_vouchers.invoice_date', 'sales_vouchers.status', 'customers.customer_name', 'customers.mobile', 'branches.name', 'warehouses.name', 'users.name', 'sales_vouchers.created_at');
        AppController::applyTenantScope($invoices, 'sales_vouchers');

        $query = DB::query()->fromSub($challans->unionAll($invoices), 'outward');
        $this->applyCommon($query, $filters, 'document_date');

        return $query
            ->when(($filters['status'] ?? null) === 'pending', fn ($q) => $q->whereIn('dispatch_status', ['draft', 'ready_to_pick', 'picking', 'packed', 'partial', 'pending']))
            ->when(($filters['status'] ?? null) === 'ready', fn ($q) => $q->whereIn('dispatch_status', ['draft', 'ready', 'ready_to_pick', 'pending']))
            ->when(!empty($filters['status']) && !in_array($filters['status'], ['pending', 'ready'], true), fn ($q) => $q->where('dispatch_status', $filters['status']))
            ->when(($filters['type'] ?? null) === 'dispatch', fn ($q) => $q->where('row_type', 'challan'))
            ->when(!empty($filters['row_type']), fn ($q) => $q->where('row_type', $filters['row_type']))
            ->when(!empty($filters['reference_type']), fn ($q) => $q->where('reference_type', str_replace('_', ' ', $filters['reference_type'])))
            ->when(!empty($filters['delivery_status']), fn ($q) => $q->where('delivery_status', $filters['delivery_status']))
            ->when(!empty($filters['sales_invoice']), fn ($q) => $q->where('sales_invoice', 'like', '%' . $filters['sales_invoice'] . '%'))
            ->when(!empty($filters['order_number']), fn ($q) => $q->where('order_number', 'like', '%' . $filters['order_number'] . '%'))
            ->when(!empty($filters['transporter']), fn ($q) => $q->where('transporter', 'like', '%' . $filters['transporter'] . '%'))
            ->when(!empty($filters['vehicle_number']), fn ($q) => $q->where('vehicle_number', 'like', '%' . $filters['vehicle_number'] . '%'));
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
            ->selectRaw("stock_reservations.reference_id as id, CONCAT('RSV-', stock_reservations.reference_id) as reservation_number, MIN(stock_reservations.created_at) as reserved_date, stock_reservations.reference_type as source_type, COALESCE(sales_orders.order_number, stock_reservations.reference_id) as source_number, customers.customer_name, branches.name as branch_name, warehouses.name as warehouse_name, COUNT(DISTINCT stock_reservations.product_id) as product_lines, COALESCE(SUM(stock_reservations.reserved_quantity),0) as reserved_quantity, COALESCE(SUM(stock_reservations.fulfilled_quantity),0) as consumed_quantity, COALESCE(SUM(stock_reservations.reserved_quantity - stock_reservations.fulfilled_quantity - stock_reservations.released_quantity),0) as remaining_quantity, MAX(stock_reservations.expires_at) as expiry, CASE WHEN COALESCE(SUM(stock_reservations.released_quantity),0) >= COALESCE(SUM(stock_reservations.reserved_quantity),0) THEN 'released' WHEN COALESCE(SUM(stock_reservations.fulfilled_quantity),0) >= COALESCE(SUM(stock_reservations.reserved_quantity),0) THEN 'consumed' WHEN COALESCE(SUM(stock_reservations.fulfilled_quantity),0) > 0 THEN 'partially_consumed' ELSE 'active' END as status")
            ->groupBy('stock_reservations.reference_id', 'stock_reservations.reference_type', 'sales_orders.order_number', 'customers.customer_name', 'branches.name', 'warehouses.name');
        AppController::applyTenantScope($query, 'stock_reservations');

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
        AppController::applyTenantScope($query, 'stock_ledgers');

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

    private function assertChallanIsNotSaleLinked(DeliveryChallan $challan): void
    {
        $references = collect([$challan->dispatch_reference, $challan->challan_number])
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->values();

        if ($references->isEmpty()) {
            return;
        }

        $invoice = SalesVoucher::query()
            ->where('business_id', $challan->business_id)
            ->whereIn('status', ['confirmed', 'approved'])
            ->where(function ($query) use ($references) {
                $query->whereIn('invoice_number', $references)
                    ->orWhereIn('voucher_number', $references);
            })
            ->first();

        if (!$invoice) {
            return;
        }

        $saleStockPosted = DB::table('stock_ledgers')
            ->where('business_id', $challan->business_id)
            ->where('transaction_type', 'sale')
            ->where('reference_type', SalesVoucher::class)
            ->where('reference_id', $invoice->id)
            ->exists();

        if ($saleStockPosted) {
            throw ValidationException::withMessages([
                'stock' => 'This dispatch references a posted sales invoice. Stock was already deducted by the invoice, so Stock Outward cannot deduct it again.',
            ]);
        }
    }

    private function periodFilters(array $filters): array
    {
        return ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']];
    }

    private function emptyPage(array $filters): LengthAwarePaginator
    {
        return DB::query()->fromRaw('(select 1 as id) as empty_rows')->whereRaw('1 = 0')->paginate($filters['per_page']);
    }
}
