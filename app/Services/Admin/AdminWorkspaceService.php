<?php

namespace App\Services\Admin;

use App\Http\Controllers\AppController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class AdminWorkspaceService
{
    public const SECTIONS = ['admin', 'staff', 'onboarding', 'users', 'saas', 'settings'];

    public function build(int $businessId, User $user, string $section): array
    {
        $section = $this->normalizeSection($section, $user);
        $routes = $this->routes();
        $permissions = $this->permissions($user);
        $counts = $this->counts($businessId);

        return [
            'summary' => $this->summary($counts, $routes, $permissions),
            'activeSection' => $section,
            'sections' => $this->sections($user),
            'metrics' => $this->metrics($businessId, $section, $counts, $routes, $permissions),
            'permissions' => $permissions,
            'routes' => $routes,
            'emptyStates' => $this->emptyStates($counts, $routes, $permissions),
        ];
    }

    public function normalizeSection(?string $section, User $user): string
    {
        $section = strtolower((string) $section);
        if (!in_array($section, self::SECTIONS, true)) {
            return 'admin';
        }
        if ($section === 'saas' && !$user->isSuperAdmin()) {
            return 'admin';
        }

        return $section;
    }

    private function summary(array $counts, array $routes, array $permissions): array
    {
        return [
            [
                'key' => 'branches',
                'label' => 'Branches',
                'value' => $counts['branches'],
                'tone' => $counts['branches'] > 0 ? 'info' : 'warn',
                'href' => $routes['admin.branches.index']['url'],
                'enabled' => $permissions['branches.view'],
                'empty' => 'No branches created yet.',
                'emptyAction' => 'Create Branch',
            ],
            [
                'key' => 'warehouses',
                'label' => 'Warehouses',
                'value' => $counts['warehouses'],
                'tone' => $counts['warehouses'] > 0 ? 'good' : 'warn',
                'href' => $routes['admin.warehouses.index']['url'],
                'enabled' => $permissions['warehouses.view'],
                'empty' => 'No warehouses created yet.',
                'emptyAction' => 'Create Warehouse',
            ],
            [
                'key' => 'stock_lines',
                'label' => 'Stock Lines',
                'value' => $counts['stock_lines'],
                'tone' => $counts['stock_lines'] > 0 ? 'money' : 'warn',
                'href' => $routes['inventory.stock-summary']['url'],
                'enabled' => $permissions['inventory.view'],
                'empty' => 'No inventory stock has been recorded yet.',
                'emptyAction' => 'Add Opening Stock',
            ],
        ];
    }

    private function metrics(int $businessId, string $section, array $counts, array $routes, array $permissions): array
    {
        return match ($section) {
            'staff' => $this->staffMetrics($businessId, $counts, $routes, $permissions),
            'onboarding' => $this->onboardingMetrics($businessId, $counts, $routes, $permissions),
            'users' => $this->userMetrics($businessId, $counts, $routes, $permissions),
            'saas' => $this->saasMetrics($businessId, $counts, $routes, $permissions),
            'settings' => $this->settingsMetrics($businessId, $counts, $routes, $permissions),
            default => $this->adminMetrics($counts, $routes, $permissions),
        };
    }

    private function adminMetrics(array $counts, array $routes, array $permissions): array
    {
        return [
            $this->metric('Branches', $counts['branches'], $routes['admin.branches.index']['url'], $permissions['branches.view']),
            $this->metric('Warehouses', $counts['warehouses'], $routes['admin.warehouses.index']['url'], $permissions['warehouses.view']),
            $this->metric('Stock Lines', $counts['stock_lines'], $routes['inventory.stock-summary']['url'], $permissions['inventory.view']),
            $this->metric('Products', $counts['products'], $routes['products.index']['url'], $permissions['products.view']),
            $this->metric('Categories', $counts['categories'], $routes['admin.categories.index']['url'], $permissions['masters.view']),
            $this->metric('Brands', $counts['brands'], $routes['admin.brands.index']['url'], $permissions['masters.view']),
            $this->metric('Units', $counts['units'], $routes['admin.units.index']['url'], $permissions['masters.view']),
            $this->metric('HSN Codes', $counts['hsn_codes'], $routes['admin.hsn-codes.index']['url'], $permissions['masters.view']),
            $this->metric('GST Rates', $counts['gst_rates'], $routes['admin.gst-rates.index']['url'], $permissions['masters.view']),
            $this->metric('Financial Years', $counts['financial_years'], $routes['admin.financial-years.index']['url'], $permissions['settings.view']),
        ];
    }

    private function staffMetrics(int $businessId, array $counts, array $routes, array $permissions): array
    {
        return [
            $this->metric('Total Employees', $counts['employees'], $routes['admin.employees.index']['url'], $permissions['employees.view'], 'Active'),
            $this->metric('Active Employees', $counts['active_employees'], $routes['admin.employees.index']['url'], $permissions['employees.view'], 'Active'),
            $this->metric('Inactive Employees', $counts['inactive_employees'], $routes['admin.employees.index']['url'], $permissions['employees.view'], $counts['inactive_employees'] ? 'Attention Required' : 'Configured'),
            $this->metric('Unassigned Employees', $counts['unassigned_employees'], $routes['admin.employees.index']['url'], $permissions['employees.view'], $counts['unassigned_employees'] ? 'Attention Required' : 'Configured'),
            $this->metric('Employees Without Branch', $counts['employees_without_branch'], $routes['admin.employees.index']['url'], $permissions['employees.view'], $counts['employees_without_branch'] ? 'Attention Required' : 'Configured'),
            $this->metric('Employees Without Role', $counts['employees_without_role'], $routes['admin.employees.index']['url'], $permissions['employees.view'], $counts['employees_without_role'] ? 'Attention Required' : 'Configured'),
            $this->metric('Employees Logged In Today', $this->usersQuery($businessId)->whereDate('last_login_at', now()->toDateString())->count(), $routes['admin.employees.index']['url'], $permissions['employees.view'], 'Active'),
        ];
    }

    private function onboardingMetrics(int $businessId, array $counts, array $routes, array $permissions): array
    {
        $steps = [
            $this->step('Business profile completed', $this->businessProfileComplete($businessId), $routes['admin.settings.business']['url'], $permissions['settings.view']),
            $this->step('Financial year created', $counts['financial_years'] > 0, $routes['admin.financial-years.index']['url'], $permissions['settings.view']),
            $this->step('At least one branch created', $counts['branches'] > 0, $routes['admin.branches.index']['url'], $permissions['branches.view']),
            $this->step('At least one warehouse created', $counts['warehouses'] > 0, $routes['admin.warehouses.index']['url'], $permissions['warehouses.view']),
            $this->step('GST configuration completed', $counts['gst_rates'] > 0, $routes['admin.gst-rates.index']['url'], $permissions['settings.view']),
            $this->step('Product master created', $counts['products'] > 0, $routes['products.index']['url'], $permissions['products.view']),
            $this->step('Customer or supplier created', ($counts['customers'] + $counts['suppliers']) > 0, $routes['customers.index']['url'], $permissions['customers.view']),
            $this->step('Opening stock entered', $counts['stock_lines'] > 0, $routes['inventory.opening-stock.create']['url'], $permissions['inventory.view']),
            $this->step('Employee or staff user added', ($counts['employees'] + $counts['staff_users']) > 0, $routes['admin.employees.index']['url'], $permissions['employees.view']),
            $this->step('Invoice numbering configured', Schema::hasTable('sales_vouchers') && DB::table('sales_vouchers')->where('business_id', $businessId)->exists(), $routes['sales.create']['url'], $permissions['sales.create']),
        ];
        $completed = collect($steps)->where('status', 'Completed')->count();

        array_unshift($steps, [
            'id' => 'progress',
            'metric' => 'Overall Progress',
            'value' => $completed . ' of ' . count($steps) . ' completed',
            'status' => round(($completed / count($steps)) * 100) . '%',
            'href' => $routes['onboarding.index']['url'],
            'action' => 'Open',
            'enabled' => true,
        ]);

        return $steps;
    }

    private function userMetrics(int $businessId, array $counts, array $routes, array $permissions): array
    {
        return [
            $this->metric('Total Users', $counts['users'], $routes['admin.users.index']['url'], $permissions['users.view'], 'Active'),
            $this->metric('Active Users', $counts['active_users'], $routes['admin.users.index']['url'], $permissions['users.view'], 'Active'),
            $this->metric('Inactive Users', $counts['inactive_users'], $routes['admin.users.index']['url'], $permissions['users.view'], $counts['inactive_users'] ? 'Attention Required' : 'Configured'),
            $this->metric('Pending Invitations', 0, null, false, 'Coming Soon', 'Coming Soon'),
            $this->metric('Users Without Roles', $counts['users_without_roles'], $routes['admin.users.index']['url'], $permissions['users.view'], $counts['users_without_roles'] ? 'Attention Required' : 'Configured'),
            $this->metric('Locked Users', $counts['locked_users'], $routes['admin.users.index']['url'], $permissions['users.view'], $counts['locked_users'] ? 'Attention Required' : 'Configured'),
            $this->metric('Users With Admin Access', $counts['admin_users'], $routes['admin.users.index']['url'], $permissions['users.view'], 'Active'),
        ];
    }

    private function saasMetrics(int $businessId, array $counts, array $routes, array $permissions): array
    {
        $subscription = $this->subscription($businessId);

        return [
            $this->metric('Current Subscription Plan', $subscription['plan'], $routes['admin.subscription.show']['url'], $permissions['subscriptions.view'], $subscription['status']),
            $this->metric('Subscription Status', $subscription['status'], $routes['admin.subscription.show']['url'], $permissions['subscriptions.view'], $subscription['status']),
            $this->metric('Trial Expiry', $subscription['trial_ends_at'] ?: 'Not set', $routes['admin.subscription.show']['url'], $permissions['subscriptions.view'], $subscription['trial_ends_at'] ? 'Active' : 'Not Configured'),
            $this->metric('Billing Cycle', $subscription['billing_cycle'], $routes['admin.billing.index']['url'], $permissions['subscriptions.view'], $subscription['billing_cycle'] ? 'Configured' : 'Not Configured'),
            $this->metric('User Limit', $subscription['user_limit'], $routes['admin.subscription.show']['url'], $permissions['subscriptions.view']),
            $this->metric('Branch Limit', $subscription['branch_limit'], $routes['admin.subscription.show']['url'], $permissions['subscriptions.view']),
            $this->metric('Warehouse Limit', $subscription['warehouse_limit'], $routes['admin.subscription.show']['url'], $permissions['subscriptions.view']),
            $this->metric('Enabled Modules', 'Current app modules', $routes['admin.modules.index']['url'], $permissions['subscriptions.view'], 'Configured'),
        ];
    }

    private function settingsMetrics(int $businessId, array $counts, array $routes, array $permissions): array
    {
        return [
            $this->metric('Business Profile', $this->businessProfileComplete($businessId) ? 'Configured' : 'Pending', $routes['admin.settings.business']['url'], $permissions['settings.view'], $this->businessProfileComplete($businessId) ? 'Configured' : 'Not Configured'),
            $this->metric('Invoice Settings', Schema::hasTable('sales_vouchers') ? 'Available' : 'Coming Soon', $routes['sales.create']['url'], $permissions['sales.create'], Schema::hasTable('sales_vouchers') ? 'Configured' : 'Coming Soon'),
            $this->metric('GST Settings', $counts['gst_rates'], $routes['admin.gst-rates.index']['url'], $permissions['settings.view']),
            $this->metric('Financial Year', $counts['financial_years'], $routes['admin.financial-years.index']['url'], $permissions['settings.view']),
            $this->metric('Number Sequences', 'Invoice numbering', $routes['sales.create']['url'], $permissions['sales.create'], Schema::hasTable('sales_vouchers') ? 'Configured' : 'Coming Soon'),
            $this->metric('Currency and Locale', 'INR / ₹ / en-IN', null, false, 'Configured', 'Configured'),
            $this->metric('Timezone', config('app.timezone'), null, false, 'Configured', 'Configured'),
            $this->metric('Notification Preferences', 'Not available', null, false, 'Coming Soon', 'Coming Soon'),
            $this->metric('Backup Settings', 'Not available', null, false, 'Coming Soon', 'Coming Soon'),
            $this->metric('Security Settings', 'Role permissions', $routes['admin.roles.index']['url'], $permissions['roles.manage'], 'Configured'),
        ];
    }

    private function counts(int $businessId): array
    {
        $hasEmployees = Schema::hasTable('employees');
        $employees = $hasEmployees ? $this->tableCount('employees', $businessId) : 0;
        $users = $this->usersQuery($businessId);

        return [
            'branches' => $this->activeCount('branches', $businessId),
            'warehouses' => $this->activeCount('warehouses', $businessId),
            'stock_lines' => $this->stockLineCount($businessId),
            'products' => $this->activeCount('products', $businessId),
            'categories' => $this->tableCount('product_categories', $businessId, true),
            'brands' => $this->tableCount('brands', $businessId, true),
            'units' => $this->tableCount('units', $businessId, true),
            'hsn_codes' => $this->tableCount('hsn_masters', $businessId, true),
            'gst_rates' => $this->tableCount('hsn_tax_rates', $businessId, true),
            'financial_years' => $this->financialYearCount($businessId),
            'customers' => $this->activeCount('customers', $businessId),
            'suppliers' => $this->activeCount('suppliers', $businessId),
            'employees' => $employees,
            'active_employees' => $hasEmployees ? $this->employeeQuery($businessId)->whereIn('status', ['active', 'confirmed', 'probation'])->count() : 0,
            'inactive_employees' => $hasEmployees ? $this->employeeQuery($businessId)->whereNotIn('status', ['active', 'confirmed', 'probation'])->count() : 0,
            'unassigned_employees' => $hasEmployees ? $this->employeeQuery($businessId)->whereNull('user_id')->count() : 0,
            'employees_without_branch' => $hasEmployees ? $this->employeeQuery($businessId)->whereNull('branch_id')->count() : 0,
            'employees_without_role' => $hasEmployees ? $this->employeeQuery($businessId)->leftJoin('users', 'users.id', '=', 'employees.user_id')->whereNull('users.role_id')->count() : 0,
            'users' => (clone $users)->count(),
            'active_users' => (clone $users)->where('status', 'active')->where('is_active', 1)->count(),
            'inactive_users' => (clone $users)->where(fn ($q) => $q->where('status', '!=', 'active')->orWhere('is_active', 0))->count(),
            'users_without_roles' => (clone $users)->whereNull('role_id')->count(),
            'locked_users' => Schema::hasColumn('users', 'locked_until') ? (clone $users)->where('locked_until', '>', now())->count() : 0,
            'admin_users' => (clone $users)->whereIn('role_id', [1, 2])->count(),
            'staff_users' => (clone $users)->where('role_id', 3)->count(),
        ];
    }

    private function stockLineCount(int $businessId): int
    {
        if (!Schema::hasTable('stock_ledgers')) {
            return 0;
        }

        return (int) DB::query()
            ->fromSub(
                DB::table('stock_ledgers')
                    ->where('business_id', $businessId)
                    ->groupBy('business_id', 'branch_id', 'warehouse_id', 'product_id', 'product_variant_id', 'batch_id')
                    ->selectRaw('business_id, branch_id, warehouse_id, product_id, product_variant_id, batch_id, COALESCE(SUM(quantity_in), 0) - COALESCE(SUM(quantity_out), 0) as balance'),
                'stock_lines'
            )
            ->where('balance', '!=', 0)
            ->count();
    }

    private function activeCount(string $table, int $businessId): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $this->scopeBusiness($query, $table, $businessId);
        if (Schema::hasColumn($table, 'status')) {
            $query->where('status', 'active');
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function tableCount(string $table, int $businessId, bool $globalAllowed = false): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $this->scopeBusiness($query, $table, $businessId, $globalAllowed);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    private function employeeQuery(int $businessId)
    {
        if (!Schema::hasTable('employees')) {
            return DB::table('users')->whereRaw('1 = 0');
        }

        return DB::table('employees')->where('employees.business_id', $businessId)->when(Schema::hasColumn('employees', 'deleted_at'), fn ($q) => $q->whereNull('employees.deleted_at'));
    }

    private function usersQuery(int $businessId)
    {
        $query = DB::table('users');
        if (Schema::hasColumn('users', 'tenant_id')) {
            $query->where('tenant_id', $businessId);
        }

        return $query;
    }

    private function financialYearCount(int $businessId): int
    {
        if (Schema::hasTable('accounting_periods')) {
            return $this->tableCount('accounting_periods', $businessId);
        }
        if (Schema::hasTable('financial_year_closures')) {
            return $this->tableCount('financial_year_closures', $businessId);
        }
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'financial_year')) {
            return DB::table('companies')->where('id', $businessId)->whereNotNull('financial_year')->count();
        }

        return 0;
    }

    private function businessProfileComplete(int $businessId): bool
    {
        if (!Schema::hasTable('companies')) {
            return false;
        }

        $company = DB::table('companies')->where('id', $businessId)->first(['name']);
        return $company && filled($company->name ?? null);
    }

    private function subscription(int $businessId): array
    {
        if (!Schema::hasTable('business_subscriptions')) {
            return ['plan' => 'Not configured', 'status' => 'Not Configured', 'trial_ends_at' => null, 'billing_cycle' => null, 'user_limit' => 0, 'branch_limit' => 0, 'warehouse_limit' => 0];
        }

        $row = DB::table('business_subscriptions')
            ->leftJoin('subscription_plans', 'subscription_plans.id', '=', 'business_subscriptions.subscription_plan_id')
            ->where('business_subscriptions.business_id', $businessId)
            ->latest('business_subscriptions.id')
            ->first([
                'business_subscriptions.status',
                'business_subscriptions.trial_ends_at',
                'business_subscriptions.billing_cycle',
                'subscription_plans.name as plan_name',
                'subscription_plans.user_limit',
                'subscription_plans.branch_limit',
                'subscription_plans.warehouse_limit',
            ]);

        return [
            'plan' => $row->plan_name ?? 'Not configured',
            'status' => $this->statusLabel($row->status ?? 'Not Configured'),
            'trial_ends_at' => $row->trial_ends_at ?? null,
            'billing_cycle' => $row->billing_cycle ?? null,
            'user_limit' => $row->user_limit ?? 0,
            'branch_limit' => $row->branch_limit ?? 0,
            'warehouse_limit' => $row->warehouse_limit ?? 0,
        ];
    }

    private function emptyStates(array $counts, array $routes, array $permissions): array
    {
        return [
            'branches' => ['show' => $counts['branches'] === 0, 'message' => 'No branches created yet.', 'action' => 'Create Branch', 'href' => $routes['admin.branches.create']['url'], 'enabled' => $permissions['branches.manage']],
            'warehouses' => ['show' => $counts['warehouses'] === 0, 'message' => 'No warehouses created yet.', 'action' => 'Create Warehouse', 'href' => $routes['admin.warehouses.create']['url'], 'enabled' => $permissions['warehouses.manage']],
            'stock' => ['show' => $counts['stock_lines'] === 0, 'message' => 'No inventory stock has been recorded yet.', 'action' => 'Add Opening Stock', 'href' => $routes['inventory.opening-stock.create']['url'], 'enabled' => $permissions['inventory.view']],
        ];
    }

    private function sections(User $user): array
    {
        return collect(self::SECTIONS)
            ->filter(fn ($section) => $section !== 'saas' || $user->isSuperAdmin())
            ->values()
            ->all();
    }

    private function permissions(User $user): array
    {
        $admin = $user->isSuperAdmin() || $user->isAdmin();
        $voucherCreate = AppController::canOpen('vouchers') && $admin;

        return [
            'admin.workspace.view' => $admin,
            'branches.view' => $admin,
            'branches.manage' => $admin,
            'warehouses.view' => $admin,
            'warehouses.manage' => $admin,
            'inventory.view' => AppController::canOpen('inventory') || AppController::canOpen('inventory-current-stock') || $admin,
            'products.view' => AppController::canOpen('products') || $admin,
            'customers.view' => AppController::canOpen('customers') || $admin,
            'employees.view' => AppController::canOpen('employees') || $admin,
            'employees.create' => $admin,
            'employees.update' => $admin,
            'users.view' => AppController::canOpen('users') || $admin,
            'roles.manage' => $user->isSuperAdmin(),
            'masters.view' => AppController::canOpen('masters') || $admin,
            'settings.view' => AppController::canOpen('settings') || $admin,
            'settings.update' => $admin,
            'subscriptions.view' => $user->isSuperAdmin(),
            'sales.create' => AppController::canOpen('pos'),
            'vouchers.create' => $voucherCreate,
            'accounting.create' => $voucherCreate,
            'reports.view' => AppController::canOpen('reports') || $admin,
        ];
    }

    private function routes(): array
    {
        return [
            'admin.workspace' => $this->routeInfo('app.admin.workspace'),
            'admin.branches.index' => $this->routeInfo('app.setup.branches', [], 'Branches'),
            'admin.branches.create' => $this->routeInfo('app.setup.branches', ['action' => 'create'], 'Branches'),
            'admin.warehouses.index' => $this->routeInfo('app.warehouse.warehouses', [], 'Warehouses'),
            'admin.warehouses.create' => $this->routeInfo('app.warehouse.warehouses', ['action' => 'create'], 'Warehouses'),
            'admin.masters.index' => $this->routeInfo('app.setup.masters'),
            'admin.employees.index' => $this->routeInfo('app.setup.employees'),
            'admin.employees.create' => $this->routeInfo('app.setup.employees', ['action' => 'create']),
            'admin.users.index' => $this->routeInfo('app.setup.users'),
            'admin.roles.index' => $this->routeInfo('app.setup.users', ['tab' => 'roles'], 'Roles'),
            'admin.categories.index' => $this->routeInfo('app.setup.masters', ['tab' => 'category']),
            'admin.brands.index' => $this->routeInfo('app.setup.masters', ['tab' => 'brand']),
            'admin.units.index' => $this->routeInfo('app.setup.masters', ['tab' => 'unit']),
            'admin.hsn-codes.index' => $this->routeInfo('app.setup.masters', ['tab' => 'hsn']),
            'admin.gst-rates.index' => $this->routeInfo('app.accounting.gst'),
            'admin.financial-years.index' => $this->routeInfo('app.reports.financial.dashboard'),
            'admin.subscription.show' => $this->routeInfo('app.setup.saas', ['section' => 'saas'], 'Subscription'),
            'admin.billing.index' => $this->routeInfo('app.setup.saas', ['section' => 'saas'], 'Billing'),
            'admin.modules.index' => $this->routeInfo('app.setup.saas', ['section' => 'saas'], 'Modules'),
            'admin.settings.business' => $this->routeInfo('app.setup.settings', ['section' => 'business']),
            'admin.settings.index' => $this->routeInfo('app.setup.settings'),
            'inventory.stock-summary' => $this->routeInfo('app.inventory.current-stock'),
            'inventory.opening-stock.create' => $this->routeInfo('app.inventory.opening-stock', ['action' => 'create']),
            'products.index' => $this->routeInfo('app.inventory.products'),
            'customers.index' => $this->routeInfo('app.sales.customers'),
            'suppliers.index' => $this->routeInfo('app.purchase.suppliers'),
            'business.dashboard' => $this->routeInfo(Route::has('business.dashboard') ? 'business.dashboard' : 'app.dashboard'),
            'staff.workspace' => $this->routeInfo('app.staff.workspace'),
            'onboarding.index' => $this->routeInfo(Route::has('app.onboarding') ? 'app.onboarding' : 'app.admin.onboarding'),
            'sales.create' => $this->routeInfo('app.sales.pos'),
            'accounting.vouchers.create' => $this->routeInfo('app.accounting.vouchers', ['action' => 'create']),
            'reports.index' => $this->routeInfo('app.reports.business'),
            'profile.edit' => $this->routeInfo(Route::has('profile.edit') ? 'profile.edit' : 'app.dashboard'),
            'logout' => $this->routeInfo('logout'),
        ];
    }

    private function routeInfo(string $name, array $parameters = [], ?string $label = null): array
    {
        return [
            'name' => $name,
            'url' => Route::has($name) ? route($name, $parameters) : null,
            'label' => $label,
        ];
    }

    private function metric(string $metric, mixed $value, ?string $href, bool $enabled, ?string $status = null, ?string $action = 'Manage'): array
    {
        return [
            'id' => str($metric)->slug()->toString(),
            'metric' => $metric,
            'value' => $value,
            'status' => $status ?: ((is_numeric($value) ? (float) $value : 0) > 0 ? 'Configured' : 'Not Configured'),
            'href' => $href,
            'enabled' => $enabled && filled($href),
            'action' => $action,
        ];
    }

    private function step(string $metric, bool $complete, ?string $href, bool $enabled): array
    {
        return $this->metric($metric, $complete ? 'Yes' : 'No', $href, $enabled, $complete ? 'Completed' : 'Pending', 'Open');
    }

    private function scopeBusiness($query, string $table, int $businessId, bool $globalAllowed = false): void
    {
        foreach (['business_id', 'tenant_id', 'company_id'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $globalAllowed
                    ? $query->where(fn ($inner) => $inner->whereNull($column)->orWhere($column, $businessId))
                    : $query->where($column, $businessId);
                return;
            }
        }
    }

    private function statusLabel(string $status): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $status));
    }
}
