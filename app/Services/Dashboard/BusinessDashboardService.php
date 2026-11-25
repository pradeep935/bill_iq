<?php

namespace App\Services\Dashboard;

use App\Services\ProductMasterService;
use App\Services\StockService;
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
        if (Schema::hasTable('stock_ledgers')) {
            $stock['out'] = app(StockService::class)->summary(['stock_status' => 'unavailable', 'per_page' => 1])->total();
        }
        $routes = $this->routes();
        $permissions = $this->permissions($roleId);
        $accounts = $this->accountBalances($businessId);

        return [
            'home' => $this->home($businessId, $today, $stock, $accounts, $routes, $permissions),
            'quickActions' => $this->quickActions($routes, $permissions),
            'pendingActions' => $this->pendingActions($businessId, $routes, $permissions),
            'recentSales' => $permissions['sales.view'] ? $this->recentSales($businessId, $routes, $permissions) : [],
            'chart' => $this->monthlyChart($businessId, $permissions),
            'inventoryAlerts' => $permissions['inventory.view'] ? $this->inventoryAlerts($businessId, $stock, $routes) : null,
            'permissions' => $permissions,
            'routes' => $routes,
        ];
    }

    /** Headline numbers. A block is null when the role cannot open that area, so nothing leaks to the browser. */
    private function home(int $businessId, string $today, array $stock, array $accounts, array $routes, array $permissions): array
    {
        $party = function (bool $allowed, QueryBuilder $query, float $amount, ?string $url) {
            return $allowed ? [
                'amount' => $amount,
                'count' => (clone $query)->where('balance_amount', '>', 0)->count(),
                'href' => $url ? $url . '?payment_status=due' : null,
            ] : null;
        };
        $day = function (bool $allowed, QueryBuilder $query, string $column, ?string $url) use ($today) {
            if (!$allowed) {
                return null;
            }
            $query->whereDate($column, $today);

            return [
                'amount' => round((float) (clone $query)->sum('grand_total'), 2),
                'count' => (clone $query)->count(),
                'href' => $url ? $url . '?date=today&status=posted' : null,
            ];
        };
        $outputGst = (float) $this->salesQuery($businessId)->sum(DB::raw($this->taxExpression()));
        $inputGst = (float) $this->purchaseQuery($businessId)->sum(DB::raw($this->taxExpression()));
        $link = fn (bool $allowed, string $table, ?string $url) => $allowed ? ['count' => $this->countTable($table, $businessId), 'href' => $url] : null;

        return [
            'toCollect' => $party($permissions['sales.view'], $this->salesQuery($businessId), $accounts['receivables'], $routes['sales.index']['url']),
            'toPay' => $party($permissions['purchases.view'], $this->purchaseQuery($businessId), $accounts['payables'], $routes['purchases.index']['url']),
            'todaySales' => $day($permissions['sales.view'], $this->salesQuery($businessId), 'invoice_date', $routes['sales.index']['url']),
            'todayPurchases' => $day($permissions['purchases.view'], $this->purchaseQuery($businessId), 'purchase_date', $routes['purchases.index']['url']),
            'money' => $permissions['accounting.view'] ? [
                'cash' => $accounts['cash'],
                'bank' => $accounts['bank'],
                'profit' => $this->todayProfit($businessId, $today),
                // Negative means input credit is more than output tax.
                'gst' => round($outputGst - $inputGst, 2),
                'ledgerHref' => $this->url('app.accounting.ledgers'),
                'gstHref' => $routes['reports.gst-summary']['url'],
            ] : null,
            'stock' => $permissions['inventory.view'] ? [
                'value' => $stock['value'],
                'inStock' => $stock['items'],
                'out' => $stock['out'],
                'low' => $stock['low'],
                'href' => $routes['inventory.stock-summary']['url'],
            ] : null,
            'counts' => [
                'products' => $link($permissions['products.view'], 'products', $routes['products.index']['url']),
                'customers' => $link($permissions['sales.view'], 'customers', $this->url('app.sales.customers')),
                'suppliers' => $link($permissions['purchases.view'], 'suppliers', $this->url('app.purchase.suppliers')),
            ],
        ];
    }

    /** Sales value without GST (after discounts) minus the cost of the goods sold. GST is not income. */
    private function todayProfit(int $businessId, string $today): float
    {
        if (!Schema::hasTable('sales_items') || !Schema::hasTable('sales_vouchers')) {
            return 0.0;
        }
        $revenue = (float) $this->salesQuery($businessId)->whereDate('invoice_date', $today)->sum('taxable_amount');
        $cost = (float) DB::table('sales_items')
            ->join('sales_vouchers', 'sales_vouchers.id', '=', 'sales_items.sales_voucher_id')
            ->where('sales_vouchers.business_id', $businessId)
            ->whereDate('sales_vouchers.invoice_date', $today)
            ->whereIn('sales_vouchers.status', self::POSTED_STATUSES)
            ->selectRaw('COALESCE(SUM(COALESCE(sales_items.cost_rate, 0) * (COALESCE(sales_items.quantity, 0) + COALESCE(sales_items.free_quantity, 0))), 0) as cost')
            ->value('cost');

        return round($revenue - $cost, 2);
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
                'links' => [
                    'view' => $this->url('app.sales.invoices', ['sale' => $sale->id]),
                    'print' => $this->url('app.sales.invoices.print', ['sale' => $sale->id]),
                ],
            ])
            ->values()
            ->all();
    }

    private function quickActions(array $routes, array $permissions): array
    {
        $add = fn (?string $url) => $url ? $url . '?action=create' : null;
        $voucher = fn (string $tab) => $routes['accounting.vouchers.index']['url'] . '?tab=' . $tab;
        $items = [
            ['label' => 'New Sale', 'icon' => 'sale', 'href' => $routes['sales.create']['url'], 'enabled' => $permissions['sales.create']],
            ['label' => 'New Purchase', 'icon' => 'purchase', 'href' => $add($routes['purchases.index']['url']), 'enabled' => $permissions['purchases.create']],
            ['label' => 'Money In', 'icon' => 'in', 'href' => $voucher('receipt'), 'enabled' => $permissions['accounting.create']],
            ['label' => 'Money Out', 'icon' => 'out', 'href' => $voucher('payment'), 'enabled' => $permissions['accounting.create']],
            ['label' => 'Add Expense', 'icon' => 'expense', 'href' => $this->url('app.accounting.expenses'), 'enabled' => $permissions['accounting.create']],
            ['label' => 'Add Product', 'icon' => 'product', 'href' => $add($routes['products.index']['url']), 'enabled' => $permissions['products.create']],
            ['label' => 'Add Customer', 'icon' => 'customer', 'href' => $add($this->url('app.sales.customers')), 'enabled' => $permissions['customers.create']],
            ['label' => 'Add Supplier', 'icon' => 'supplier', 'href' => $add($this->url('app.purchase.suppliers')), 'enabled' => $permissions['suppliers.create']],
        ];

        return array_values(array_filter($items, fn ($item) => $item['enabled'] && $item['href']));
    }

    /** Things that need attention today. Each row opens the matching filtered list. */
    private function pendingActions(int $businessId, array $routes, array $permissions): array
    {
        $items = [];
        $add = function (string $key, string $label, int $count, ?float $amount, ?string $url, array $query, string $hint) use (&$items) {
            $items[] = [
                'key' => $key, 'label' => $label, 'count' => $count, 'amount' => $amount, 'hint' => $hint,
                'href' => $url ? $url . '?' . http_build_query($query) : null,
            ];
        };

        if ($permissions['purchases.view']) {
            $due = $this->purchaseQuery($businessId)->where('balance_amount', '>', 0);
            $add('supplier_due', 'Supplier payments pending', (clone $due)->count(), round((float) (clone $due)->sum('balance_amount'), 2), $routes['purchases.index']['url'], ['payment_status' => 'due'], 'Bills you still have to pay');
        }
        if ($permissions['sales.view']) {
            $due = $this->salesQuery($businessId)->where('balance_amount', '>', 0);
            $add('customer_due', 'Customer payments to collect', (clone $due)->count(), round((float) (clone $due)->sum('balance_amount'), 2), $routes['sales.index']['url'], ['payment_status' => 'due'], 'Invoices not fully paid');
        }
        if ($permissions['inventory.view']) {
            $negative = app(StockService::class)->summary(['stock_status' => 'negative', 'per_page' => 1])->total();
            $add('negative_stock', 'Products with minus stock', $negative, null, $routes['inventory.stock-summary']['url'], ['stock_status' => 'negative'], 'Check purchases or correct the stock');
        }
        if ($permissions['purchases.view']) {
            $add('draft_purchases', 'Draft purchases not posted', $this->purchaseQuery($businessId, false)->where('status', 'draft')->count(), null, $routes['purchases.index']['url'], ['status' => 'draft'], 'Stock is not added until posted');
        }
        if ($permissions['sales.view']) {
            $add('draft_sales', 'Draft sales invoices', $this->salesQuery($businessId, false)->where('status', 'draft')->count(), null, $routes['sales.index']['url'], ['status' => 'draft'], 'Not yet billed');
        }
        if ($permissions['products.view']) {
            $unpriced = app(ProductMasterService::class)->list(['price_missing' => 1, 'per_page' => 1])->total();
            $add('price_missing', 'Products without selling price', $unpriced, null, $routes['products.index']['url'], ['price' => 'missing'], 'Set the rate before billing');
        }

        return [
            'items' => $items,
            'open' => collect($items)->where('count', '>', 0)->count(),
        ];
    }

    /** Posted sales and purchases for the last six calendar months. */
    private function monthlyChart(int $businessId, array $permissions): array
    {
        $start = CarbonImmutable::now(config('app.timezone'))->startOfMonth()->subMonths(5);
        $months = collect(range(0, 5))->map(fn ($i) => $start->addMonths($i));
        $series = fn (bool $allowed, callable $query, string $column) => $allowed
            ? $months->map(fn ($m) => round((float) $query()->whereDate($column, '>=', $m->toDateString())->whereDate($column, '<=', $m->endOfMonth()->toDateString())->sum('grand_total'), 2))->values()->all()
            : [];

        return [
            'labels' => $months->map(fn ($m) => $m->format('M Y'))->values()->all(),
            'sales' => $series($permissions['sales.view'], fn () => $this->salesQuery($businessId), 'invoice_date'),
            'purchases' => $series($permissions['purchases.view'], fn () => $this->purchaseQuery($businessId), 'purchase_date'),
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
            'outOfStock' => $products->filter(fn ($row) => $row['quantity'] <= 0)->sortBy('quantity')->take(5)->values()->all(),
            'expiringBatches' => $expiring,
            'viewAll' => $routes['inventory.stock-summary']['url'] . '?stock_status=unavailable',
        ];
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

    private function label(?string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', (string) $value));
    }
}
