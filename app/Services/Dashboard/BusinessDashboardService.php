<?php

namespace App\Services\Dashboard;

use App\Http\Controllers\AppController;
use App\Models\ProductBatch;
use App\Models\SalesVoucher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class BusinessDashboardService
{
    private const POSTED_STATUSES = ['approved', 'confirmed', 'posted', 'completed'];
    private const EXCLUDED_STATUSES = ['draft', 'hold', 'cancelled', 'reversed', 'deleted', 'rejected'];

    public function data(): array
    {
        $businessId = AppController::businessId();
        $roleId = AppController::roleId();
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        $stock = $this->stockSnapshot($businessId);
        $routes = $this->routes();
        $permissions = $this->permissions($roleId);
        $accounts = $this->accountBalances($businessId);

        return [
            'summary' => $this->summary($businessId, $today, $stock),
            'modules' => $this->modules($businessId, $today, $stock, $accounts, $routes, $permissions),
            'recentSales' => $this->recentSales($businessId, $routes, $permissions),
            'quickActions' => $this->quickActions($routes, $permissions),
            'accountingChecklist' => $this->accountingChecklist($businessId, $today, $routes, $permissions),
            'charts' => $this->charts($businessId),
            'inventoryAlerts' => $this->inventoryAlerts($businessId, $stock, $routes),
            'metrics' => $this->metrics($businessId, $today, $stock, $accounts),
            'notifications' => $this->notifications($businessId, $stock, $routes),
            'permissions' => $permissions,
            'routes' => $routes,
        ];
    }

    private function summary(int $businessId, string $today, array $stock): array
    {
        $sales = $this->salesQuery($businessId)->whereDate('invoice_date', $today);
        $purchases = $this->purchaseQuery($businessId)->whereDate('purchase_date', $today);
        $outputGst = $this->salesQuery($businessId)->sum(DB::raw($this->taxExpression()));
        $inputGst = $this->purchaseQuery($businessId)->sum(DB::raw($this->taxExpression()));

        return [
            [
                'key' => 'today_sales',
                'label' => 'Today Sales',
                'value' => round((float) (clone $sales)->sum('grand_total'), 2),
                'count' => (int) (clone $sales)->count(),
                'format' => 'currency',
                'hint' => (clone $sales)->count() . ' invoices today',
                'href' => $this->url('app.sales.invoices', ['date' => 'today']),
            ],
            [
                'key' => 'today_purchase',
                'label' => 'Today Purchase',
                'value' => round((float) (clone $purchases)->sum('grand_total'), 2),
                'count' => (int) (clone $purchases)->count(),
                'format' => 'currency',
                'hint' => (clone $purchases)->count() . ' purchase bills today',
                'href' => $this->url('app.purchase.bills', ['date' => 'today']),
            ],
            [
                'key' => 'gst_payable',
                'label' => 'GST Payable',
                'value' => max(0, round((float) $outputGst - (float) $inputGst, 2)),
                'count' => null,
                'format' => 'currency',
                'hint' => 'Output GST minus input GST',
                'href' => $this->url('app.accounting.gst'),
            ],
            [
                'key' => 'stock_value',
                'label' => 'Stock Value',
                'value' => round((float) $stock['value'], 2),
                'count' => (int) $stock['items'],
                'format' => 'currency',
                'hint' => $stock['items'] . ' stock items from ledger',
                'href' => $this->url('app.inventory.current-stock'),
            ],
        ];
    }

    private function modules(int $businessId, string $today, array $stock, array $accounts, array $routes, array $permissions): array
    {
        $todaySales = $this->salesQuery($businessId)->whereDate('invoice_date', $today);

        return [
            [
                'key' => 'catalog',
                'label' => 'Catalog',
                'href' => $routes['products.index']['url'],
                'enabled' => $permissions['products.view'],
                'stats' => [
                    ['label' => 'Products', 'value' => $this->countTable('products', $businessId)],
                    ['label' => 'Categories', 'value' => $this->countTable('product_categories', $businessId, true)],
                    ['label' => 'Brands', 'value' => $this->countTable('brands', $businessId, true)],
                    ['label' => 'HSN', 'value' => $this->countTable('hsn_masters', $businessId, true)],
                ],
            ],
            [
                'key' => 'billing',
                'label' => 'Billing',
                'href' => $routes['sales.index']['url'],
                'enabled' => $permissions['sales.view'],
                'stats' => [
                    ['label' => 'Today Invoices', 'value' => (int) (clone $todaySales)->count()],
                    ['label' => 'Today Amount', 'value' => round((float) (clone $todaySales)->sum('grand_total'), 2), 'format' => 'currency'],
                    ['label' => 'Pending', 'value' => $this->salesQuery($businessId, false)->whereNotIn('status', self::EXCLUDED_STATUSES)->where('balance_amount', '>', 0)->count()],
                ],
            ],
            [
                'key' => 'inventory',
                'label' => 'Inventory',
                'href' => $routes['inventory.dashboard']['url'],
                'enabled' => $permissions['inventory.view'],
                'stats' => [
                    ['label' => 'Stock Items', 'value' => $stock['items']],
                    ['label' => 'Low Stock', 'value' => $stock['low']],
                    ['label' => 'Out of Stock', 'value' => $stock['out']],
                    ['label' => 'Stock Value', 'value' => $stock['value'], 'format' => 'currency'],
                ],
            ],
            [
                'key' => 'accounts',
                'label' => 'Accounts',
                'href' => $routes['accounting.dashboard']['url'],
                'enabled' => $permissions['accounting.view'],
                'stats' => [
                    ['label' => 'Receivables', 'value' => $accounts['receivables'], 'format' => 'currency'],
                    ['label' => 'Payables', 'value' => $accounts['payables'], 'format' => 'currency'],
                    ['label' => 'Cash', 'value' => $accounts['cash'], 'format' => 'currency'],
                    ['label' => 'Bank', 'value' => $accounts['bank'], 'format' => 'currency'],
                ],
            ],
        ];
    }

    private function recentSales(int $businessId, array $routes, array $permissions): array
    {
        if (!Schema::hasTable('sales_vouchers')) {
            return [];
        }

        return SalesVoucher::query()
            ->with('customer')
            ->where('business_id', $businessId)
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->latest('invoice_date')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (SalesVoucher $sale) => [
                'id' => $sale->id,
                'invoice_number' => $sale->invoice_number ?: $sale->voucher_number,
                'date' => optional($sale->invoice_date)->format('Y-m-d'),
                'customer' => $sale->customer_name_snapshot ?: optional($sale->customer)->customer_name ?: 'Walk-in Customer',
                'total' => (float) $sale->grand_total,
                'payment_status' => $this->label($sale->payment_status ?: 'unpaid'),
                'invoice_status' => $this->label($sale->status ?: 'draft'),
                'can_edit' => $permissions['sales.edit'] && in_array($sale->status, ['draft', 'hold'], true),
                'links' => [
                    'view' => $this->url('app.sales.invoices', ['sale' => $sale->id]),
                    'edit' => $this->url('app.sales.invoices', ['sale' => $sale->id, 'mode' => 'edit']),
                    'print' => $this->url('app.sales.invoices', ['sale' => $sale->id, 'print' => 1]),
                ],
            ])
            ->values()
            ->all();
    }

    private function quickActions(array $routes, array $permissions): array
    {
        $items = [
            ['label' => 'New Sale', 'route' => 'sales.create', 'href' => $routes['sales.create']['url'], 'enabled' => $permissions['sales.create']],
            ['label' => 'New Purchase', 'route' => 'purchases.create', 'href' => $routes['purchases.create']['url'], 'enabled' => $permissions['purchases.create']],
            ['label' => 'Receipt Voucher', 'route' => 'accounting.vouchers.create', 'href' => $routes['accounting.vouchers.create']['url'] . '?type=receipt', 'enabled' => $permissions['accounting.create']],
            ['label' => 'Payment Voucher', 'route' => 'accounting.vouchers.create', 'href' => $routes['accounting.vouchers.create']['url'] . '?type=payment', 'enabled' => $permissions['accounting.create']],
            ['label' => 'Expense Voucher', 'route' => 'accounting.vouchers.create', 'href' => $routes['accounting.vouchers.create']['url'] . '?type=expense', 'enabled' => $permissions['accounting.create']],
            ['label' => 'Journal Voucher', 'route' => 'accounting.vouchers.create', 'href' => $routes['accounting.vouchers.create']['url'] . '?type=journal', 'enabled' => $permissions['accounting.create']],
            ['label' => 'Add Product', 'route' => 'products.create', 'href' => $routes['products.create']['url'], 'enabled' => $permissions['products.create']],
            ['label' => 'Add Customer', 'route' => 'customers.create', 'href' => $routes['customers.create']['url'], 'enabled' => $permissions['customers.create']],
            ['label' => 'Add Supplier', 'route' => 'suppliers.create', 'href' => $routes['suppliers.create']['url'], 'enabled' => $permissions['suppliers.create']],
        ];

        return array_values(array_filter($items, fn ($item) => $item['enabled']));
    }

    private function accountingChecklist(int $businessId, string $today, array $routes, array $permissions): array
    {
        $items = [
            [
                'label' => 'Sales invoices posted to receivables and output GST',
                'status' => $this->checkStatus(Schema::hasTable('sales_vouchers'), $this->salesQuery($businessId)->exists()),
                'href' => $routes['sales.index']['url'],
                'enabled' => $permissions['sales.view'],
            ],
            [
                'label' => 'Purchase bills posted to payables and input GST',
                'status' => $this->checkStatus(Schema::hasTable('purchase_vouchers'), $this->purchaseQuery($businessId)->exists()),
                'href' => $routes['purchases.index']['url'],
                'enabled' => $permissions['purchases.view'],
            ],
            [
                'label' => 'Stock ledger updated through stock transactions',
                'status' => $this->checkStatus(Schema::hasTable('stock_ledgers'), DB::table('stock_ledgers')->where('business_id', $businessId)->exists()),
                'href' => $routes['inventory.stock-ledger']['url'],
                'enabled' => $permissions['inventory.view'],
            ],
            [
                'label' => 'Cash and bank vouchers posted',
                'status' => $this->checkStatus(Schema::hasTable('journal_vouchers'), $this->postedJournals($businessId)->whereDate('voucher_date', $today)->exists()),
                'href' => $routes['accounting.vouchers.index']['url'],
                'enabled' => $permissions['accounting.view'],
            ],
            [
                'label' => 'Bank reconciliation completed',
                'status' => $this->bankReconciliationStatus($businessId, $today),
                'href' => $routes['accounting.bank-reconciliation']['url'],
                'enabled' => false,
                'comingSoon' => true,
            ],
            [
                'label' => 'Daily cash closing completed',
                'status' => Schema::hasTable('cash_closings') ? $this->checkStatus(true, DB::table('cash_closings')->where('business_id', $businessId)->whereDate('closing_date', $today)->exists()) : 'warning',
                'href' => null,
                'enabled' => false,
                'comingSoon' => true,
            ],
        ];
        $completed = collect($items)->where('status', 'completed')->count();

        return [
            'items' => $items,
            'completed' => $completed,
            'total' => count($items),
            'percentage' => count($items) ? round(($completed / count($items)) * 100) : 0,
        ];
    }

    private function charts(int $businessId): array
    {
        $start = CarbonImmutable::now(config('app.timezone'))->subDays(6)->startOfDay();
        $labels = collect(range(0, 6))->map(fn ($i) => $start->addDays($i)->format('Y-m-d'))->values();
        $sales = $this->dateSeries($this->salesQuery($businessId, false), 'invoice_date', 'grand_total', $labels);
        $purchases = $this->dateSeries($this->purchaseQuery($businessId, false), 'purchase_date', 'grand_total', $labels);

        return [
            'salesLast7Days' => ['labels' => $labels, 'values' => $sales],
            'purchasesLast7Days' => ['labels' => $labels, 'values' => $purchases],
            'salesVsPurchasesMonth' => [
                'labels' => ['Sales', 'Purchases'],
                'values' => [
                    (float) $this->salesQuery($businessId)->whereMonth('invoice_date', now()->month)->whereYear('invoice_date', now()->year)->sum('grand_total'),
                    (float) $this->purchaseQuery($businessId)->whereMonth('purchase_date', now()->month)->whereYear('purchase_date', now()->year)->sum('grand_total'),
                ],
            ],
        ];
    }

    private function inventoryAlerts(int $businessId, array $stock, array $routes): array
    {
        $products = collect($stock['rows'])->map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'name' => $row->product_name ?: 'Product #' . $row->product_id,
            'sku' => $row->sku,
            'quantity' => round((float) $row->quantity_on_hand, 3),
            'reorder_level' => round((float) $row->reorder_level, 3),
            'href' => $routes['products.index']['url'] . '?product=' . $row->product_id,
        ]);

        $expiring = [];
        if (Schema::hasTable('product_batches') && Schema::hasColumn('product_batches', 'expiry_date')) {
            $expiring = ProductBatch::query()
                ->with('product')
                ->where('business_id', $businessId)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', now()->toDateString())
                ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
                ->orderBy('expiry_date')
                ->limit(5)
                ->get()
                ->map(fn (ProductBatch $batch) => [
                    'id' => $batch->id,
                    'name' => optional($batch->product)->name ?: 'Batch #' . $batch->id,
                    'batch' => $batch->batch_no ?: $batch->batch_number,
                    'expiry_date' => optional($batch->expiry_date)->format('Y-m-d'),
                    'href' => $routes['inventory.batches']['url'] . '?batch=' . $batch->id,
                ])
                ->values()
                ->all();
        }

        return [
            'lowStock' => $products->filter(fn ($row) => $row['quantity'] > 0 && $row['reorder_level'] > 0 && $row['quantity'] <= $row['reorder_level'])->take(5)->values()->all(),
            'outOfStock' => $products->filter(fn ($row) => $row['quantity'] <= 0)->take(5)->values()->all(),
            'expiringBatches' => $expiring,
            'viewAll' => $routes['inventory.stock-summary']['url'],
        ];
    }

    private function metrics(int $businessId, string $today, array $stock, array $accounts): array
    {
        $todayProfit = 0.0;
        if (Schema::hasTable('sales_items') && Schema::hasTable('sales_vouchers')) {
            $todayProfit = (float) DB::table('sales_items')
                ->join('sales_vouchers', 'sales_vouchers.id', '=', 'sales_items.sales_voucher_id')
                ->where('sales_vouchers.business_id', $businessId)
                ->whereDate('sales_vouchers.invoice_date', $today)
                ->whereIn('sales_vouchers.status', self::POSTED_STATUSES)
                ->selectRaw('COALESCE(SUM(sales_items.line_total - ((COALESCE(sales_items.cost_rate, 0)) * (COALESCE(sales_items.quantity, 0) + COALESCE(sales_items.free_quantity, 0)))), 0) as profit')
                ->value('profit');
        }

        return [
            ['label' => "Today's Profit", 'value' => round($todayProfit, 2), 'format' => 'currency'],
            ['label' => 'Cash in Hand', 'value' => $accounts['cash'], 'format' => 'currency'],
            ['label' => 'Bank Balance', 'value' => $accounts['bank'], 'format' => 'currency'],
            ['label' => 'Receivables', 'value' => $accounts['receivables'], 'format' => 'currency'],
            ['label' => 'Payables', 'value' => $accounts['payables'], 'format' => 'currency'],
            ['label' => 'Low Stock', 'value' => $stock['low']],
            ['label' => 'Out of Stock', 'value' => $stock['out']],
            ['label' => 'Customers', 'value' => $this->countTable('customers', $businessId)],
            ['label' => 'Suppliers', 'value' => $this->countTable('suppliers', $businessId)],
            ['label' => 'Products', 'value' => $this->countTable('products', $businessId)],
        ];
    }

    private function notifications(int $businessId, array $stock, array $routes): array
    {
        $items = [];
        if ($stock['low'] > 0) {
            $items[] = ['type' => 'warning', 'label' => $stock['low'] . ' low-stock products', 'href' => $routes['inventory.stock-summary']['url'] . '?stock_status=low'];
        }
        if ($stock['out'] > 0) {
            $items[] = ['type' => 'danger', 'label' => $stock['out'] . ' out-of-stock products', 'href' => $routes['inventory.stock-summary']['url'] . '?stock_status=out'];
        }
        $pendingPayments = $this->salesQuery($businessId, false)->whereNotIn('status', self::EXCLUDED_STATUSES)->where('balance_amount', '>', 0)->count();
        if ($pendingPayments > 0) {
            $items[] = ['type' => 'info', 'label' => $pendingPayments . ' invoices awaiting payment', 'href' => $routes['sales.index']['url'] . '?payment_status=pending'];
        }

        return $items;
    }

    private function stockSnapshot(int $businessId): array
    {
        if (!Schema::hasTable('stock_ledgers')) {
            return ['value' => 0, 'items' => 0, 'low' => 0, 'out' => 0, 'rows' => []];
        }

        $rows = DB::table('stock_ledgers')
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.business_id', $businessId)
            ->when(Schema::hasColumn('products', 'deleted_at'), fn ($q) => $q->whereNull('products.deleted_at'))
            ->groupBy('stock_ledgers.product_id', 'products.name', 'products.sku', 'products.minimum_stock', 'products.reorder_stock')
            ->selectRaw('
                stock_ledgers.product_id,
                products.name as product_name,
                products.sku,
                COALESCE(NULLIF(products.reorder_stock, 0), products.minimum_stock, 0) as reorder_level,
                COALESCE(SUM(stock_ledgers.quantity_in), 0) - COALESCE(SUM(stock_ledgers.quantity_out), 0) as quantity_on_hand,
                CASE
                    WHEN COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 0) = 0
                    THEN 0
                    ELSE COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in * stock_ledgers.unit_cost ELSE 0 END), 0)
                        / COALESCE(SUM(CASE WHEN stock_ledgers.quantity_in > 0 THEN stock_ledgers.quantity_in ELSE 0 END), 1)
                END as average_cost
            ')
            ->get()
            ->map(function ($row) {
                $row->stock_value = round((float) $row->quantity_on_hand * (float) $row->average_cost, 2);
                return $row;
            });

        return [
            'value' => round((float) $rows->sum('stock_value'), 2),
            'items' => $rows->where('quantity_on_hand', '>', 0)->pluck('product_id')->unique()->count(),
            'low' => $rows->filter(fn ($row) => (float) $row->quantity_on_hand > 0 && (float) $row->reorder_level > 0 && (float) $row->quantity_on_hand <= (float) $row->reorder_level)->count(),
            'out' => $rows->filter(fn ($row) => (float) $row->quantity_on_hand <= 0)->count(),
            'rows' => $rows->values()->all(),
        ];
    }

    private function accountBalances(int $businessId): array
    {
        $receivables = Schema::hasTable('sales_vouchers') ? (float) $this->salesQuery($businessId)->sum('balance_amount') : 0.0;
        $payables = Schema::hasTable('purchase_vouchers') ? (float) $this->purchaseQuery($businessId)->sum('balance_amount') : 0.0;

        return [
            'receivables' => round(max(0, $receivables), 2),
            'payables' => round(max(0, $payables), 2),
            'cash' => $this->accountTypeBalance($businessId, ['cash']),
            'bank' => $this->accountTypeBalance($businessId, ['bank']),
        ];
    }

    private function accountTypeBalance(int $businessId, array $types): float
    {
        if (!Schema::hasTable('accounts') || !Schema::hasTable('journal_entries')) {
            return 0.0;
        }

        return round((float) DB::table('journal_entries')
            ->join('accounts', 'accounts.id', '=', 'journal_entries.account_id')
            ->where('journal_entries.business_id', $businessId)
            ->whereIn('accounts.account_type', $types)
            ->selectRaw('COALESCE(SUM(journal_entries.debit_amount - journal_entries.credit_amount), 0) as balance')
            ->value('balance'), 2);
    }

    private function salesQuery(int $businessId, bool $postedOnly = true): QueryBuilder
    {
        $query = DB::table('sales_vouchers')->where('business_id', $businessId);
        return $postedOnly ? $query->whereIn('status', self::POSTED_STATUSES) : $query;
    }

    private function purchaseQuery(int $businessId, bool $postedOnly = true): QueryBuilder
    {
        $query = DB::table('purchase_vouchers')->where('business_id', $businessId);
        return $postedOnly ? $query->whereIn('status', self::POSTED_STATUSES) : $query;
    }

    private function postedJournals(int $businessId): QueryBuilder
    {
        return DB::table('journal_vouchers')->where('business_id', $businessId)->whereIn('status', self::POSTED_STATUSES);
    }

    private function dateSeries(QueryBuilder $query, string $dateColumn, string $amountColumn, $labels): array
    {
        $rows = $query
            ->whereDate($dateColumn, '>=', $labels->first())
            ->selectRaw("DATE($dateColumn) as day, COALESCE(SUM($amountColumn), 0) as total")
            ->groupBy(DB::raw("DATE($dateColumn)"))
            ->pluck('total', 'day');

        return $labels->map(fn ($label) => round((float) ($rows[$label] ?? 0), 2))->values()->all();
    }

    private function countTable(string $table, int $businessId, bool $globalAllowed = false): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $column = $this->businessColumn($table);
        if ($column && Schema::hasColumn($table, $column)) {
            $globalAllowed
                ? $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, $businessId))
                : $query->where($column, $businessId);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function businessColumn(string $table): ?string
    {
        foreach (['business_id', 'tenant_id', 'company_id'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function permissions(int $roleId): array
    {
        return [
            'sales.view' => AppController::canOpen('sales') || AppController::canOpen('pos'),
            'sales.create' => AppController::canOpen('pos'),
            'sales.edit' => in_array($roleId, [1, 2], true),
            'purchases.view' => AppController::canOpen('purchases'),
            'purchases.create' => AppController::canOpen('purchases') && in_array($roleId, [1, 2], true),
            'products.view' => AppController::canOpen('products'),
            'products.create' => AppController::canOpen('products') && in_array($roleId, [1, 2], true),
            'customers.create' => AppController::canOpen('customers'),
            'suppliers.create' => AppController::canOpen('suppliers') && in_array($roleId, [1, 2], true),
            'inventory.view' => AppController::canOpen('inventory') || AppController::canOpen('inventory-current-stock'),
            'accounting.view' => AppController::canOpen('vouchers') || AppController::canOpen('accounts'),
            'accounting.create' => AppController::canOpen('vouchers') && in_array($roleId, [1, 2], true),
            'reports.view' => AppController::canOpen('reports'),
            'profile.view' => true,
        ];
    }

    private function routes(): array
    {
        return [
            'business.dashboard' => ['name' => 'business.dashboard', 'url' => $this->url('business.dashboard', [], 'app.dashboard')],
            'sales.index' => ['name' => 'app.sales.invoices', 'url' => $this->url('app.sales.invoices')],
            'sales.create' => ['name' => 'app.sales.pos', 'url' => $this->url('app.sales.pos')],
            'purchases.index' => ['name' => 'app.purchase.bills', 'url' => $this->url('app.purchase.bills')],
            'purchases.create' => ['name' => 'app.purchase.bills', 'url' => $this->url('app.purchase.bills', ['action' => 'create'])],
            'products.index' => ['name' => 'app.inventory.products', 'url' => $this->url('app.inventory.products')],
            'products.create' => ['name' => 'app.inventory.products', 'url' => $this->url('app.inventory.products', ['action' => 'create'])],
            'customers.create' => ['name' => 'app.sales.customers', 'url' => $this->url('app.sales.customers', ['action' => 'create'])],
            'suppliers.create' => ['name' => 'app.purchase.suppliers', 'url' => $this->url('app.purchase.suppliers', ['action' => 'create'])],
            'inventory.dashboard' => ['name' => 'app.inventory.dashboard', 'url' => $this->url('app.inventory.dashboard')],
            'inventory.stock-summary' => ['name' => 'app.inventory.current-stock', 'url' => $this->url('app.inventory.current-stock')],
            'inventory.stock-ledger' => ['name' => 'app.reports.stock-ledger', 'url' => $this->url('app.reports.stock-ledger')],
            'inventory.batches' => ['name' => 'app.inventory.batches', 'url' => $this->url('app.inventory.batches')],
            'accounting.dashboard' => ['name' => 'app.accounting.chart-of-accounts', 'url' => $this->url('app.accounting.chart-of-accounts')],
            'accounting.vouchers.index' => ['name' => 'app.accounting.vouchers', 'url' => $this->url('app.accounting.vouchers')],
            'accounting.vouchers.create' => ['name' => 'app.accounting.vouchers', 'url' => $this->url('app.accounting.vouchers', ['action' => 'create'])],
            'accounting.bank-reconciliation' => ['name' => 'app.accounting.expenses', 'url' => $this->url('app.accounting.expenses', ['tab' => 'bank-reconciliation'])],
            'reports.index' => ['name' => 'app.reports.business', 'url' => $this->url('app.reports.business')],
            'reports.gst-summary' => ['name' => 'app.accounting.gst', 'url' => $this->url('app.accounting.gst')],
            'profile.edit' => ['name' => 'profile.edit', 'url' => $this->url('profile.edit')],
            'logout' => ['name' => 'logout', 'url' => $this->url('logout')],
            'admin.workspace' => ['name' => 'app.admin.workspace', 'url' => $this->url('app.admin.workspace')],
            'staff.workspace' => ['name' => 'app.staff.workspace', 'url' => $this->url('app.staff.workspace')],
            'onboarding' => ['name' => 'app.admin.onboarding', 'url' => $this->url('app.admin.onboarding')],
        ];
    }

    private function url(string $route, array $parameters = [], ?string $fallbackRoute = null): ?string
    {
        $route = Route::has($route) ? $route : $fallbackRoute;
        if (!$route || !Route::has($route)) {
            return null;
        }

        return route($route, $parameters);
    }

    private function taxExpression(): string
    {
        return 'COALESCE(cgst_amount, 0) + COALESCE(sgst_amount, 0) + COALESCE(igst_amount, 0) + COALESCE(cess_amount, 0)';
    }

    private function bankReconciliationStatus(int $businessId, string $today): string
    {
        if (!Schema::hasTable('bank_reconciliations')) {
            return 'warning';
        }

        $query = DB::table('bank_reconciliations')->where('business_id', $businessId);

        if (Schema::hasColumn('bank_reconciliations', 'statement_start_date') && Schema::hasColumn('bank_reconciliations', 'statement_end_date')) {
            $query->where(function (QueryBuilder $range) use ($today) {
                $range->where(function (QueryBuilder $bounded) use ($today) {
                    $bounded
                        ->whereDate('statement_start_date', '<=', $today)
                        ->whereDate('statement_end_date', '>=', $today);
                })->orWhereDate('created_at', $today);
            });
        } elseif (Schema::hasColumn('bank_reconciliations', 'reconciliation_date')) {
            $query->whereDate('reconciliation_date', $today);
        } else {
            $query->whereDate('created_at', $today);
        }

        return $this->checkStatus(true, $query->exists());
    }

    private function checkStatus(bool $available, bool $completed): string
    {
        if (!$available) {
            return 'warning';
        }

        return $completed ? 'completed' : 'pending';
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', (string) $value));
    }
}
