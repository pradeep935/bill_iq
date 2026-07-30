<?php

namespace App\Services\Staff;

use App\Http\Controllers\AppController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class StaffWorkspaceService
{
    public function build(User $user, int $businessId, array $filters = []): array
    {
        $context = $this->context($user, $businessId, $filters);
        $permissions = $this->permissions($user);
        $routes = $this->routes();

        return [
            'context' => $context,
            'summary' => $this->summary($user, $businessId, $context, $permissions, $routes),
            'quickActions' => $this->quickActions($permissions, $routes),
            'tasks' => $this->tasks($user, $businessId, $context, $permissions, $routes),
            'recentActivity' => $this->recentActivity($user, $businessId, $context, $routes),
            'inventoryAlerts' => $this->inventoryAlerts($businessId, $context, $permissions, $routes),
            'permissions' => $permissions,
            'routes' => $routes,
        ];
    }

    public function context(User $user, int $businessId, array $filters = []): array
    {
        $roleId = (int) ($user->role_id ?? 3);
        $isAdmin = in_array($roleId, [1, 2], true);
        $allowedBranches = $this->allowedBranches($user, $businessId, $isAdmin);
        $selectedBranchId = $filters['branch_id'] ?? null;

        if ($selectedBranchId && !collect($allowedBranches)->pluck('id')->contains((int) $selectedBranchId)) {
            abort(403, 'Selected branch is not assigned to this user.');
        }

        if (!$selectedBranchId && count($allowedBranches) === 1) {
            $selectedBranchId = $allowedBranches[0]['id'];
        }

        $allowedWarehouses = $this->allowedWarehouses($businessId, $selectedBranchId, $isAdmin || $selectedBranchId);
        $selectedWarehouseId = $filters['warehouse_id'] ?? null;

        if ($selectedWarehouseId && !collect($allowedWarehouses)->pluck('id')->contains((int) $selectedWarehouseId)) {
            abort(403, 'Selected warehouse is not assigned to this user.');
        }

        return [
            'employee_name' => $user->name,
            'role' => $this->roleLabel($roleId),
            'financial_year' => config('app.financial_year', '2026-27'),
            'selected_branch_id' => $selectedBranchId ? (int) $selectedBranchId : null,
            'selected_warehouse_id' => $selectedWarehouseId ? (int) $selectedWarehouseId : null,
            'allowed_branches' => $allowedBranches,
            'allowed_warehouses' => $allowedWarehouses,
            'branch_required' => !$isAdmin && empty($allowedBranches),
            'warehouse_required' => !$isAdmin && !empty($allowedBranches) && empty($allowedWarehouses),
            'shift_status' => $this->shiftStatus($user, $businessId),
        ];
    }

    private function summary(User $user, int $businessId, array $context, array $permissions, array $routes): array
    {
        $today = now()->toDateString();
        $sales = $this->salesQuery($businessId, $context)
            ->whereDate('invoice_date', $today)
            ->where(function ($query) use ($user) {
                $query->where('created_by', $user->id)->orWhere('salesperson_id', $user->id);
            });

        $cards = [];

        if ($permissions['sales.view']) {
            $cards[] = [
                'key' => 'today_sales',
                'label' => "Today's Sales",
                'value' => round((float) (clone $sales)->sum('grand_total'), 2),
                'subvalue' => (clone $sales)->count() . ' invoices',
                'format' => 'currency',
                'href' => $routes['sales.index']['url'] . '?date=today&mine=1',
                'empty' => 'No sales recorded by you today.',
            ];
        }

        $pendingTasks = count($this->tasks($user, $businessId, $context, $permissions, $routes, false));
        $cards[] = [
            'key' => 'pending_tasks',
            'label' => 'Pending Tasks',
            'value' => $pendingTasks,
            'subvalue' => 'items needing action',
            'href' => $routes['staff.tasks.index']['url'],
            'empty' => 'No pending tasks assigned to you.',
        ];

        if ($permissions['inventory.view']) {
            $lowStock = $this->stockRows($businessId, $context)
                ->filter(fn ($row) => (float) $row->quantity_on_hand > 0 && (float) $row->reorder_level > 0 && (float) $row->quantity_on_hand <= (float) $row->reorder_level)
                ->count();
            $cards[] = [
                'key' => 'low_stock',
                'label' => 'Low Stock',
                'value' => $lowStock,
                'subvalue' => 'assigned location',
                'href' => $routes['inventory.stock-summary']['url'] . '?status=low',
                'empty' => 'No inventory alerts for your assigned location.',
            ];

            $cards[] = [
                'key' => 'pending_stock_counts',
                'label' => 'Pending Stock Counts',
                'value' => $this->pendingStockCounts($businessId, $context, $user->id),
                'subvalue' => 'open counts',
                'href' => $routes['inventory.stock-counts.index']['url'] . '?status=pending&mine=1',
                'empty' => 'No pending stock counts assigned to you.',
            ];
        }

        if ($permissions['vouchers.view']) {
            $cards[] = [
                'key' => 'today_collections',
                'label' => "Today's Collections",
                'value' => $this->todayCollections($businessId, $context, $user->id),
                'subvalue' => 'receipts recorded',
                'format' => 'currency',
                'href' => $routes['accounting.vouchers.index']['url'] . '?type=receipt&date=today&mine=1',
                'empty' => 'No collections recorded today.',
            ];
        }

        if ($permissions['attendance.view']) {
            $cards[] = [
                'key' => 'attendance',
                'label' => "Today's Attendance",
                'value' => $context['shift_status']['label'],
                'subvalue' => $context['shift_status']['detail'],
                'href' => $routes['staff.attendance.index']['url'],
                'empty' => 'Attendance has not been recorded today.',
            ];
        }

        return $cards;
    }

    private function quickActions(array $permissions, array $routes): array
    {
        $actions = [
            ['key' => 'new_sale', 'label' => 'New Sale', 'href' => $routes['sales.create']['url'], 'enabled' => $permissions['sales.create']],
            ['key' => 'receipt', 'label' => 'Receipt Entry', 'href' => $routes['accounting.vouchers.create']['url'] . '?type=receipt', 'enabled' => $permissions['vouchers.create']],
            ['key' => 'payment', 'label' => 'Payment Voucher', 'href' => $routes['accounting.vouchers.create']['url'] . '?type=payment', 'enabled' => $permissions['vouchers.create']],
            ['key' => 'expense', 'label' => 'Expense Voucher', 'href' => $routes['accounting.vouchers.create']['url'] . '?type=expense', 'enabled' => $permissions['vouchers.create']],
            ['key' => 'new_purchase', 'label' => 'New Purchase', 'href' => $routes['purchases.create']['url'], 'enabled' => $permissions['purchases.create']],
            ['key' => 'stock_count', 'label' => 'Stock Count', 'href' => $routes['inventory.stock-counts.create']['url'], 'enabled' => $permissions['inventory.stock-count.create']],
            ['key' => 'stock_transfer', 'label' => 'Stock Transfer', 'href' => $routes['inventory.transfers.index']['url'], 'enabled' => $permissions['inventory.transfer.view']],
            ['key' => 'barcode_scan', 'label' => 'Barcode Scan', 'href' => $routes['inventory.barcode-center']['url'], 'enabled' => $permissions['inventory.view']],
            ['key' => 'reports', 'label' => 'Reports', 'href' => $routes['reports.index']['url'], 'enabled' => $permissions['reports.view']],
            ['key' => 'masters', 'label' => 'Open Masters', 'href' => $routes['admin.masters.index']['url'], 'enabled' => $permissions['masters.manage']],
            ['key' => 'employees', 'label' => 'Employees', 'href' => $routes['admin.employees.index']['url'], 'enabled' => $permissions['employees.view']],
        ];

        return array_values(array_filter($actions, fn ($action) => $action['enabled'] && filled($action['href'])));
    }

    private function tasks(User $user, int $businessId, array $context, array $permissions, array $routes, bool $limit = true): array
    {
        if ($context['branch_required'] || $context['warehouse_required']) {
            return [];
        }

        $tasks = collect();

        if ($permissions['sales.view'] && Schema::hasTable('sales_vouchers')) {
            $sales = $this->salesQuery($businessId, $context)
                ->whereIn('status', ['draft', 'hold'])
                ->where(function ($query) use ($user) {
                    $query->where('created_by', $user->id)->orWhere('salesperson_id', $user->id);
                })
                ->latest('updated_at')
                ->limit(5)
                ->get(['id', 'invoice_number', 'voucher_number', 'invoice_date', 'status', 'branch_id', 'warehouse_id']);

            foreach ($sales as $sale) {
                $tasks->push($this->task('Draft sale needs completion', 'Sales', $sale->invoice_number ?: $sale->voucher_number, $sale->invoice_date, 'Pending', $routes['sales.index']['url'] . '?sale=' . $sale->id));
            }
        }

        if ($permissions['inventory.view'] && Schema::hasTable('stock_count_sessions')) {
            $counts = DB::table('stock_count_sessions')
                ->where('business_id', $businessId)
                ->whereIn('status', ['draft', 'planned', 'in_progress'])
                ->when($context['selected_branch_id'], fn ($q) => $q->where('branch_id', $context['selected_branch_id']))
                ->when($context['selected_warehouse_id'], fn ($q) => $q->where('warehouse_id', $context['selected_warehouse_id']))
                ->where(function ($query) use ($user) {
                    $query->when(Schema::hasColumn('stock_count_sessions', 'assigned_to'), fn ($q) => $q->where('assigned_to', $user->id))
                        ->when(Schema::hasColumn('stock_count_sessions', 'created_by'), fn ($q) => $q->orWhere('created_by', $user->id));
                })
                ->latest('id')
                ->limit(5)
                ->get(['id', 'session_number', 'count_date', 'status', 'branch_id', 'warehouse_id']);

            foreach ($counts as $count) {
                $tasks->push($this->task('Stock count pending', 'Inventory', $count->session_number, $count->count_date, 'In Progress', $routes['inventory.stock-counts.index']['url'] . '?session=' . $count->id, $this->locationLabel($context, $count->branch_id ?? null, $count->warehouse_id ?? null)));
            }
        }

        if ($permissions['purchases.view'] && Schema::hasTable('purchase_vouchers')) {
            $purchases = DB::table('purchase_vouchers')
                ->where('business_id', $businessId)
                ->whereIn('status', ['draft', 'pending', 'submitted'])
                ->where('created_by', $user->id)
                ->when($context['selected_branch_id'], fn ($q) => $q->where('branch_id', $context['selected_branch_id']))
                ->when($context['selected_warehouse_id'], fn ($q) => $q->where('warehouse_id', $context['selected_warehouse_id']))
                ->latest('updated_at')
                ->limit(5)
                ->get(['id', 'voucher_number', 'purchase_date', 'status']);

            foreach ($purchases as $purchase) {
                $tasks->push($this->task('Purchase requires posting', 'Purchase', $purchase->voucher_number, $purchase->purchase_date, 'Waiting Approval', $routes['purchases.index']['url'] . '?purchase=' . $purchase->id, $this->locationLabel($context)));
            }
        }

        $sorted = $tasks->sortByDesc('date')->values();

        return ($limit ? $sorted->take(5) : $sorted)->values()->all();
    }

    private function recentActivity(User $user, int $businessId, array $context, array $routes): array
    {
        if (Schema::hasTable('audit_logs')) {
            $businessColumn = Schema::hasColumn('audit_logs', 'client_id') ? 'client_id' : (Schema::hasColumn('audit_logs', 'tenant_id') ? 'tenant_id' : null);
            $userColumn = Schema::hasColumn('audit_logs', 'changed_by_user_id') ? 'changed_by_user_id' : (Schema::hasColumn('audit_logs', 'actor_id') ? 'actor_id' : null);

            return DB::table('audit_logs')
                ->when($businessColumn, fn ($query) => $query->where($businessColumn, $businessId))
                ->when($userColumn, fn ($query) => $query->where($userColumn, $user->id))
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'activity' => $row->summary ?? (($row->action_type ?? $row->action ?? 'Activity') . ' ' . ($row->module_name ?? $row->module ?? '')),
                    'reference' => $row->record_id ? '#' . $row->record_id : '-',
                    'date' => $row->created_at,
                    'status' => $row->action_type ?? $row->action ?? 'Done',
                    'href' => null,
                ])
                ->values()
                ->all();
        }

        $activities = collect();
        if (Schema::hasTable('sales_vouchers')) {
            $this->salesQuery($businessId, $context)
                ->where('created_by', $user->id)
                ->latest('created_at')
                ->limit(5)
                ->get(['id', 'invoice_number', 'voucher_number', 'created_at', 'status'])
                ->each(fn ($sale) => $activities->push([
                    'activity' => 'Sale created',
                    'reference' => $sale->invoice_number ?: $sale->voucher_number,
                    'date' => $sale->created_at,
                    'status' => $this->label($sale->status),
                    'href' => $routes['sales.index']['url'] . '?sale=' . $sale->id,
                ]));
        }

        return $activities->sortByDesc('date')->take(10)->values()->all();
    }

    private function inventoryAlerts(int $businessId, array $context, array $permissions, array $routes): array
    {
        if (!$permissions['inventory.view']) {
            return [];
        }

        return $this->stockRows($businessId, $context)
            ->filter(fn ($row) => (float) $row->quantity_on_hand <= 0 || ((float) $row->reorder_level > 0 && (float) $row->quantity_on_hand <= (float) $row->reorder_level))
            ->take(5)
            ->map(fn ($row) => [
                'title' => (float) $row->quantity_on_hand <= 0 ? 'Out of stock' : 'Low stock',
                'product' => $row->product_name,
                'quantity' => round((float) $row->quantity_on_hand, 3),
                'status' => (float) $row->quantity_on_hand <= 0 ? 'Out' : 'Low',
                'href' => $routes['inventory.stock-summary']['url'] . '?product_id=' . $row->product_id,
            ])
            ->values()
            ->all();
    }

    private function permissions(User $user): array
    {
        $roleId = (int) ($user->role_id ?? 3);
        $admin = in_array($roleId, [1, 2], true);
        $staff = $roleId === 3;

        return [
            'staff.workspace.view' => $admin || $staff,
            'sales.view' => AppController::canOpen('sales') || AppController::canOpen('pos'),
            'sales.create' => AppController::canOpen('pos'),
            'purchases.view' => AppController::canOpen('purchases') && $admin,
            'purchases.create' => AppController::canOpen('purchases') && $admin,
            'inventory.view' => AppController::canOpen('inventory') || AppController::canOpen('inventory-current-stock') || AppController::canOpen('stock-ledger'),
            'inventory.stock-entry.create' => AppController::canOpen('inventory-vouchers') && $admin,
            'inventory.stock-count.view' => AppController::canOpen('inventory') || AppController::canOpen('inventory-current-stock'),
            'inventory.stock-count.create' => (AppController::canOpen('inventory') || AppController::canOpen('inventory-current-stock')) && $admin,
            'inventory.transfer.view' => AppController::canOpen('inventory-transfer') && $admin,
            'vouchers.view' => AppController::canOpen('vouchers') && $admin,
            'vouchers.create' => AppController::canOpen('vouchers') && $admin,
            'reports.view' => AppController::canOpen('reports') && $admin,
            'attendance.view' => Schema::hasTable('attendance_records'),
            'tasks.view' => true,
            'approvals.view' => $admin,
            'employees.view' => AppController::canOpen('employees') && $admin,
            'masters.manage' => AppController::canOpen('masters') && $admin,
            'admin.workspace.view' => $admin,
            'accounting.create' => AppController::canOpen('vouchers') && $admin,
        ];
    }

    private function routes(): array
    {
        return [
            'staff.workspace' => $this->routeInfo('app.staff.workspace'),
            'staff.tasks.index' => $this->routeInfo('app.staff.tasks'),
            'staff.attendance.index' => $this->routeInfo('app.staff.attendance'),
            'sales.index' => $this->routeInfo('app.sales.invoices'),
            'sales.create' => $this->routeInfo('app.sales.pos'),
            'purchases.index' => $this->routeInfo('app.purchase.bills'),
            'purchases.create' => $this->routeInfo('app.purchase.bills', ['action' => 'create']),
            'inventory.stock-summary' => $this->routeInfo('app.inventory.current-stock'),
            'inventory.stock-entry.create' => $this->routeInfo('app.inventory.vouchers', ['action' => 'create']),
            'inventory.stock-counts.index' => $this->routeInfo('app.inventory.dashboard', ['tab' => 'stock-counts']),
            'inventory.stock-counts.create' => $this->routeInfo('app.inventory.dashboard', ['tab' => 'stock-counts', 'action' => 'create']),
            'inventory.transfers.index' => $this->routeInfo('app.warehouse.transfer'),
            'inventory.barcode-center' => $this->routeInfo('app.inventory.barcode-center'),
            'accounting.vouchers.index' => $this->routeInfo('app.accounting.vouchers'),
            'accounting.vouchers.create' => $this->routeInfo('app.accounting.vouchers', ['action' => 'create']),
            'reports.index' => $this->routeInfo('app.reports.business'),
            'profile.edit' => $this->routeInfo(Route::has('profile.edit') ? 'profile.edit' : 'app.dashboard'),
            'business.dashboard' => $this->routeInfo(Route::has('business.dashboard') ? 'business.dashboard' : 'app.dashboard'),
            'admin.workspace' => $this->routeInfo('app.admin.workspace'),
            'admin.masters.index' => $this->routeInfo('app.setup.masters'),
            'admin.employees.index' => $this->routeInfo('app.setup.employees'),
            'onboarding.index' => $this->routeInfo(Route::has('app.onboarding') ? 'app.onboarding' : 'app.admin.onboarding'),
            'logout' => $this->routeInfo('logout'),
        ];
    }

    private function allowedBranches(User $user, int $businessId, bool $isAdmin): array
    {
        if (!Schema::hasTable('branches')) {
            return [];
        }

        $columns = ['id', 'name'];
        if (Schema::hasColumn('branches', 'code')) {
            $columns[] = 'code';
        }

        return DB::table('branches')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->when(!$isAdmin, fn ($q) => $q->where('id', $user->branch_id))
            ->orderBy('name')
            ->get($columns)
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'code' => $row->code ?? null])
            ->values()
            ->all();
    }

    private function allowedWarehouses(int $businessId, ?int $branchId, bool $hasBranchAccess): array
    {
        if (!Schema::hasTable('warehouses') || !$hasBranchAccess) {
            return [];
        }

        return DB::table('warehouses')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'code'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'branch_id' => (int) $row->branch_id, 'name' => $row->name, 'code' => $row->code])
            ->values()
            ->all();
    }

    private function salesQuery(int $businessId, array $context)
    {
        $query = DB::table('sales_vouchers')->where('business_id', $businessId);
        $this->applyScope($query, $context);
        return $query;
    }

    private function stockRows(int $businessId, array $context)
    {
        if (!Schema::hasTable('stock_ledgers') || $context['branch_required'] || $context['warehouse_required']) {
            return collect();
        }

        $query = DB::table('stock_ledgers')
            ->join('products', 'products.id', '=', 'stock_ledgers.product_id')
            ->where('stock_ledgers.business_id', $businessId)
            ->when($context['selected_branch_id'], fn ($q) => $q->where('stock_ledgers.branch_id', $context['selected_branch_id']))
            ->when($context['selected_warehouse_id'], fn ($q) => $q->where('stock_ledgers.warehouse_id', $context['selected_warehouse_id']))
            ->when(!$context['selected_branch_id'] && !empty($context['allowed_branches']), fn ($q) => $q->whereIn('stock_ledgers.branch_id', collect($context['allowed_branches'])->pluck('id')))
            ->groupBy('stock_ledgers.product_id', 'products.name', 'products.sku', 'products.minimum_stock', 'products.reorder_stock')
            ->selectRaw('
                stock_ledgers.product_id,
                products.name as product_name,
                products.sku,
                COALESCE(NULLIF(products.reorder_stock, 0), products.minimum_stock, 0) as reorder_level,
                COALESCE(SUM(stock_ledgers.quantity_in), 0) - COALESCE(SUM(stock_ledgers.quantity_out), 0) as quantity_on_hand
            ');

        return $query->get();
    }

    private function pendingStockCounts(int $businessId, array $context, int $userId): int
    {
        if (!Schema::hasTable('stock_count_sessions') || $context['branch_required'] || $context['warehouse_required']) {
            return 0;
        }

        return DB::table('stock_count_sessions')
            ->where('business_id', $businessId)
            ->whereIn('status', ['draft', 'planned', 'in_progress'])
            ->where(function ($query) use ($userId) {
                $query->when(Schema::hasColumn('stock_count_sessions', 'assigned_to'), fn ($q) => $q->where('assigned_to', $userId))
                    ->when(Schema::hasColumn('stock_count_sessions', 'created_by'), fn ($q) => $q->orWhere('created_by', $userId));
            })
            ->when($context['selected_branch_id'], fn ($q) => $q->where('branch_id', $context['selected_branch_id']))
            ->when($context['selected_warehouse_id'], fn ($q) => $q->where('warehouse_id', $context['selected_warehouse_id']))
            ->count();
    }

    private function todayCollections(int $businessId, array $context, int $userId): float
    {
        if (Schema::hasTable('receipt_vouchers')) {
            $query = DB::table('receipt_vouchers')
                ->where('business_id', $businessId)
                ->where('created_by', $userId)
                ->whereDate('receipt_date', now()->toDateString())
                ->when($context['branch_required'] || $context['warehouse_required'], fn ($q) => $q->whereRaw('1 = 0'))
                ->when($context['selected_branch_id'], fn ($q) => $q->where('branch_id', $context['selected_branch_id']))
                ->when(!$context['selected_branch_id'] && !empty($context['allowed_branches']), fn ($q) => $q->whereIn('branch_id', collect($context['allowed_branches'])->pluck('id')));

            return round((float) $query->sum('amount'), 2);
        }

        if (!Schema::hasTable('sales_payments')) {
            return 0.0;
        }

        $query = DB::table('sales_payments')
            ->where('business_id', $businessId)
            ->where('created_by', $userId)
            ->whereDate('payment_date', now()->toDateString());

        if (Schema::hasTable('sales_vouchers')) {
            $query->join('sales_vouchers', 'sales_vouchers.id', '=', 'sales_payments.sales_voucher_id');
            $query->when($context['branch_required'] || $context['warehouse_required'], fn ($q) => $q->whereRaw('1 = 0'))
                ->when($context['selected_branch_id'], fn ($q) => $q->where('sales_vouchers.branch_id', $context['selected_branch_id']))
                ->when($context['selected_warehouse_id'], fn ($q) => $q->where('sales_vouchers.warehouse_id', $context['selected_warehouse_id']))
                ->when(!$context['selected_branch_id'] && !empty($context['allowed_branches']), fn ($q) => $q->whereIn('sales_vouchers.branch_id', collect($context['allowed_branches'])->pluck('id')));
        }

        return round((float) $query->sum('sales_payments.amount'), 2);
    }

    private function shiftStatus(User $user, int $businessId): array
    {
        if (!Schema::hasTable('attendance_records')) {
            return ['label' => 'Not Available', 'detail' => 'Attendance module is not enabled'];
        }

        $employeeId = $this->employeeId($user, $businessId);

        if (!$employeeId) {
            return ['label' => 'Not Linked', 'detail' => 'No employee record linked to your login'];
        }

        $record = DB::table('attendance_records')
            ->where('business_id', $businessId)
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', now()->toDateString())
            ->latest('id')
            ->first();

        if (!$record) {
            return ['label' => 'Not Checked In', 'detail' => 'No attendance recorded today'];
        }

        return [
            'label' => $this->label($record->attendance_status ?? 'Checked In'),
            'detail' => $record->first_in_at ? 'Checked in at ' . $record->first_in_at : 'Attendance recorded',
        ];
    }

    private function applyScope($query, array $context): void
    {
        if ($context['branch_required'] || $context['warehouse_required']) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->when($context['selected_branch_id'], fn ($q) => $q->where('branch_id', $context['selected_branch_id']))
            ->when($context['selected_warehouse_id'], fn ($q) => $q->where('warehouse_id', $context['selected_warehouse_id']))
            ->when(!$context['selected_branch_id'] && !empty($context['allowed_branches']), fn ($q) => $q->whereIn('branch_id', collect($context['allowed_branches'])->pluck('id')));
    }

    private function task(string $title, string $module, ?string $reference, $date, string $status, ?string $href, ?string $location = null): array
    {
        return [
            'title' => $title,
            'module' => $module,
            'reference' => $reference ?: '-',
            'date' => $date ? (string) $date : null,
            'location' => $location ?: 'Assigned location',
            'priority' => 'Normal',
            'status' => $status,
            'href' => $href,
        ];
    }

    private function employeeId(User $user, int $businessId): ?int
    {
        if (!Schema::hasTable('employees')) {
            return null;
        }

        $employeeId = DB::table('employees')
            ->where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->value('id');

        return $employeeId ? (int) $employeeId : null;
    }

    private function locationLabel(array $context, ?int $branchId = null, ?int $warehouseId = null): string
    {
        $branch = collect($context['allowed_branches'])->firstWhere('id', (int) ($branchId ?: $context['selected_branch_id']));
        $warehouse = collect($context['allowed_warehouses'])->firstWhere('id', (int) ($warehouseId ?: $context['selected_warehouse_id']));

        return collect([$branch['name'] ?? null, $warehouse['name'] ?? null])->filter()->implode(' / ') ?: 'Assigned location';
    }

    private function routeInfo(string $name, array $parameters = []): array
    {
        return ['name' => $name, 'url' => Route::has($name) ? route($name, $parameters) : null];
    }

    private function roleLabel(int $roleId): string
    {
        return match ($roleId) {
            1 => 'Super Admin',
            2 => 'Business Admin',
            3 => 'Staff',
            default => 'User',
        };
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', (string) $value));
    }
}
