<?php

namespace App\Services\Onboarding;

use App\Http\Controllers\AppController;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class BusinessOnboardingService
{
    private const FILTERS = ['all', 'required', 'masters', 'inventory', 'billing', 'accounts', 'team', 'completed', 'pending'];

    public function build(User $user, int $businessId, array $filters = []): array
    {
        $filter = $this->normalizeFilter($filters['filter'] ?? 'all');
        $permissions = $this->permissions($user);
        $routes = $this->routes();
        $business = $this->business($businessId);
        $steps = collect($this->getSteps($businessId, $business, $permissions, $routes));
        $progress = $this->getProgress($steps);
        $readiness = $this->getReadiness($steps);
        $nextStep = $this->getNextRecommendedStep($steps, $routes);

        $permissions['sales.create'] = $permissions['sales.create'] && $readiness['billing']['status'] === 'ready';
        $permissions['accounting.create'] = $permissions['accounting.create'] && $readiness['accounting']['status'] === 'ready';

        return [
            'business' => $this->businessContext($business, $businessId),
            'summary' => $this->summary($progress, $readiness),
            'steps' => $this->filterSteps($steps, $filter)->values()->all(),
            'progress' => $progress,
            'readiness' => $readiness,
            'nextStep' => $nextStep,
            'filters' => [
                'active' => $filter,
                'options' => collect(self::FILTERS)->map(fn ($value) => ['key' => $value, 'label' => $this->label($value)])->all(),
            ],
            'permissions' => $permissions,
            'routes' => $routes,
        ];
    }

    public function normalizeFilter(?string $filter): string
    {
        return in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    private function getSteps(int $businessId, ?object $business, array $permissions, array $routes): array
    {
        $branch = $this->branchStep($businessId, $routes);
        $warehouse = $this->warehouseStep($businessId, $branch, $routes);
        $unit = $this->unitStep($routes);
        $category = $this->categoryStep($businessId, $routes);
        $brand = $this->brandStep($businessId, $routes);
        $hsn = $this->hsnStep($routes);
        $gst = $this->gstStep($business, $hsn, $routes);
        $financialYear = $this->financialYearStep($businessId, $business, $routes);
        $numbering = $this->invoiceNumberingStep($routes);
        $product = $this->productStep($businessId, $unit, $category, $gst, $hsn, $routes);
        $paymentMode = $this->paymentModeStep($businessId, $routes);
        $accounts = $this->accountStep($businessId, $financialYear, $gst, $paymentMode, $routes);
        $openingStock = $this->openingStockStep($businessId, $branch, $warehouse, $product, $financialYear, $routes);
        $customers = $this->customerStep($businessId, $routes);
        $suppliers = $this->supplierStep($businessId, $routes);
        $employees = $this->employeeStep($businessId, $permissions, $routes);
        $roles = $this->roleStep($businessId, $employees, $routes);
        $profile = $this->businessProfileStep($business, $routes);
        $invoiceTemplate = $this->invoiceTemplateStep($routes);

        $steps = [
            $profile,
            $financialYear,
            $gst,
            $numbering,
            $branch,
            $warehouse,
            $unit,
            $category,
            $brand,
            $hsn,
            $product,
            $customers,
            $suppliers,
            $openingStock,
            $paymentMode,
            $accounts,
            $employees,
            $roles,
            $invoiceTemplate,
        ];

        $steps[] = $this->firstSaleStep($businessId, $steps, $routes);

        return $steps;
    }

    private function businessProfileStep(?object $business, array $routes): array
    {
        $missing = [];
        foreach (['name' => 'Business name', 'state' => 'State', 'financial_year' => 'Financial year'] as $column => $label) {
            if (Schema::hasColumn('companies', $column) && blank($business->{$column} ?? null)) {
                $missing[] = $label;
            }
        }

        $status = !$business ? 'pending' : (empty($missing) ? 'completed' : 'attention_required');
        $detail = !$business
            ? 'Business profile record was not found.'
            : (empty($missing) ? 'All supported business profile fields are configured.' : 'Missing: ' . implode(', ', $missing) . '.');

        return $this->step('business_profile', 1, 'Business Profile', 'Confirm legal business details used on invoices and reports.', 'billing', true, $status, $detail, $business ? 1 : 0, $business ? 'Review Business Profile' : 'Complete Business Profile', $routes['settings.business']['url']);
    }

    private function financialYearStep(int $businessId, ?object $business, array $routes): array
    {
        if (Schema::hasTable('accounting_periods')) {
            $period = DB::table('accounting_periods')
                ->where('business_id', $businessId)
                ->whereIn('status', ['open', 'active'])
                ->whereDate('start_date', '<=', now()->toDateString())
                ->whereDate('end_date', '>=', now()->toDateString())
                ->first();
            $count = $this->tableCount('accounting_periods', $businessId);

            if ($period) {
                return $this->step('financial_year', 2, 'Financial Year', 'Use an active accounting period for transactions.', 'accounts', true, 'completed', 'Current date belongs to an active financial year.', $count, 'Manage Financial Years', $routes['financial_years']['url']);
            }

            return $this->step('financial_year', 2, 'Financial Year', 'Use an active accounting period for transactions.', 'accounts', true, $count ? 'attention_required' : 'pending', $count ? 'Financial years exist, but none is active for today.' : 'No accounting period has been created.', $count, $count ? 'Review Financial Years' : 'Create Financial Year', $routes['financial_years']['url']);
        }

        $hasYear = filled($business->financial_year ?? null);
        return $this->step('financial_year', 2, 'Financial Year', 'Use an active accounting year for transactions.', 'accounts', true, $hasYear ? 'completed' : 'pending', $hasYear ? 'Company financial year is set to ' . $business->financial_year . '.' : 'No financial year is configured on the company profile.', $hasYear ? 1 : 0, 'Manage Financial Year', $routes['financial_years']['url']);
    }

    private function gstStep(?object $business, array $hsnStep, array $routes): array
    {
        $gstin = trim((string) ($business->gstin ?? ''));
        $state = trim((string) ($business->state ?? ''));
        $hasGstRates = Schema::hasTable('hsn_masters') && DB::table('hsn_masters')->when(Schema::hasColumn('hsn_masters', 'status'), fn ($q) => $q->where('status', 'active'))->exists();

        if ($gstin && !$state) {
            return $this->step('gst', 3, 'GST and Tax Settings', 'Validate GST registration, state and tax rates.', 'billing', true, 'attention_required', 'GSTIN is present but business state is missing.', $hasGstRates ? 1 : 0, 'Configure GST', $routes['settings.gst']['url']);
        }

        if (!$hasGstRates) {
            return $this->step('gst', 3, 'GST and Tax Settings', 'Validate GST registration, state and tax rates.', 'billing', true, 'attention_required', 'GST rate master records are not available.', 0, 'Review GST Master', $hsnStep['action_url']);
        }

        return $this->step('gst', 3, 'GST and Tax Settings', 'Validate GST registration, state and tax rates.', 'billing', true, 'completed', $gstin ? 'GSTIN, state and GST master records are available.' : 'GST master is available. Business profile is treated as non-GST until GSTIN is added.', 1, 'Configure GST', $routes['settings.gst']['url']);
    }

    private function invoiceNumberingStep(array $routes): array
    {
        $ready = Schema::hasTable('sales_vouchers') && class_exists(\App\Services\SalesInvoiceNumberService::class);
        return $this->step('invoice_numbering', 4, 'Invoice Numbering', 'Ensure generated sales invoice numbers are unique and scoped.', 'billing', true, $ready ? 'completed' : 'attention_required', $ready ? 'Sales invoices use the configured SalesInvoiceNumberService and unique voucher tables.' : 'Sales invoice numbering service is not available.', $ready ? 1 : 0, $ready ? 'Review Sales Setup' : 'Configure Invoice Numbering', $routes['settings.number_sequences']['url']);
    }

    private function branchStep(int $businessId, array $routes): array
    {
        $count = $this->activeCount('branches', $businessId);
        return $this->step('branch', 5, 'Branch', 'Create at least one active branch before stock and sales setup.', 'inventory', true, $count ? 'completed' : 'pending', $count ? $this->plural($count, 'branch') . ' configured.' : 'No active branches found.', $count, $count ? 'Manage Branches' : 'Create Branch', $routes['branches']['url']);
    }

    private function warehouseStep(int $businessId, array $branchStep, array $routes): array
    {
        $count = $this->activeCount('warehouses', $businessId);
        $unlinked = Schema::hasTable('warehouses') && Schema::hasColumn('warehouses', 'branch_id')
            ? DB::table('warehouses')->where('business_id', $businessId)->where('status', 'active')->whereNull('branch_id')->count()
            : 0;

        if ($branchStep['status'] !== 'completed') {
            return $this->step('warehouse', 6, 'Warehouse', 'Create a stock location linked to a branch.', 'inventory', true, 'blocked', 'Warehouse setup is locked until at least one branch is created.', $count, 'Create Branch', $routes['branches']['url'], ['branch']);
        }

        $status = $count ? ($unlinked ? 'attention_required' : 'completed') : 'pending';
        $detail = $count ? ($unlinked ? $unlinked . ' active warehouse needs branch assignment.' : $this->plural($count, 'warehouse') . ' configured.') : 'No active warehouses found.';
        return $this->step('warehouse', 6, 'Warehouse', 'Create a stock location linked to a branch.', 'inventory', true, $status, $detail, $count, $count ? 'Manage Warehouses' : 'Create Warehouse', $routes['warehouses']['url']);
    }

    private function unitStep(array $routes): array
    {
        $count = $this->globalActiveCount('units');
        return $this->step('units', 7, 'Units', 'Maintain units such as Piece, Box, Kg and Litre.', 'masters', true, $count ? 'completed' : 'pending', $count ? $this->plural($count, 'unit') . ' available.' : 'No active units found.', $count, $count ? 'Manage Units' : 'Add Units', $routes['masters']['url'] . '?tab=units');
    }

    private function categoryStep(int $businessId, array $routes): array
    {
        $count = $this->activeCount('product_categories', $businessId, true);
        return $this->step('categories', 8, 'Categories', 'Group products for easier billing and reporting.', 'masters', true, $count ? 'completed' : 'pending', $count ? $this->plural($count, 'category') . ' configured.' : 'No active product categories found.', $count, $count ? 'Manage Categories' : 'Add Category', $routes['masters']['url'] . '?tab=categories');
    }

    private function brandStep(int $businessId, array $routes): array
    {
        $count = $this->activeCount('brands', $businessId, true);
        return $this->step('brands', 9, 'Brands', 'Add brands when the business tracks products by brand.', 'masters', false, $count ? 'completed' : 'optional', $count ? $this->plural($count, 'brand') . ' configured.' : 'Brand setup is optional for this business.', $count, $count ? 'Manage Brands' : 'Add Brand', $routes['masters']['url'] . '?tab=brands');
    }

    private function hsnStep(array $routes): array
    {
        $count = $this->globalActiveCount('hsn_masters');
        return $this->step('hsn', 10, 'HSN Codes', 'Review centralized HSN and GST rate records.', 'billing', true, $count ? 'completed' : 'attention_required', $count ? $this->plural($count, 'HSN code') . ' available in the central master.' : 'Central HSN master has no active records.', $count, 'Review HSN Master', $routes['masters']['url'] . '?tab=hsn_codes');
    }

    private function productStep(int $businessId, array $unitStep, array $categoryStep, array $gstStep, array $hsnStep, array $routes): array
    {
        $blocked = collect([$unitStep, $categoryStep, $gstStep, $hsnStep])->filter(fn ($step) => !in_array($step['status'], ['completed', 'optional'], true))->pluck('key')->values()->all();
        $total = $this->productBase($businessId)->count();
        $valid = $this->validProducts($businessId);

        if (!empty($blocked)) {
            return $this->step('products', 11, 'Products', 'Create sellable products with tax, unit and pricing details.', 'inventory', true, 'blocked', 'Complete units, categories, GST and HSN setup before adding products.', $total, 'Complete Previous Step', $this->firstBlockedUrl($blocked, [$unitStep, $categoryStep, $gstStep, $hsnStep]), $blocked);
        }

        $status = $total === 0 ? 'pending' : ($valid > 0 ? ($valid === $total ? 'completed' : 'attention_required') : 'attention_required');
        $detail = $total === 0 ? 'No active products found.' : ($valid === $total ? $this->plural($total, 'product') . ' ready for billing.' : ($total - $valid) . ' products are missing required fields.');
        return $this->step('products', 11, 'Products', 'Create sellable products with tax, unit and pricing details.', 'inventory', true, $status, $detail, $total, $total ? 'Manage Products' : 'Add First Product', $routes['products']['url']);
    }

    private function customerStep(int $businessId, array $routes): array
    {
        $count = $this->activeCount('customers', $businessId);
        return $this->step('customers', 12, 'Customers', 'Create saved customers for credit sales and GST invoices.', 'billing', false, $count ? 'completed' : 'optional', $count ? $this->plural($count, 'customer') . ' configured.' : 'Optional because cash sales can use walk-in customers.', $count, $count ? 'Manage Customers' : 'Add Customer', $routes['customers']['url']);
    }

    private function supplierStep(int $businessId, array $routes): array
    {
        $count = $this->activeCount('suppliers', $businessId);
        return $this->step('suppliers', 13, 'Suppliers', 'Create suppliers before using purchase workflows.', 'masters', false, $count ? 'completed' : 'optional', $count ? $this->plural($count, 'supplier') . ' configured.' : 'Optional before the first sale; required before purchases.', $count, $count ? 'Manage Suppliers' : 'Add Supplier', $routes['suppliers']['url']);
    }

    private function openingStockStep(int $businessId, array $branchStep, array $warehouseStep, array $productStep, array $financialYearStep, array $routes): array
    {
        $blocked = collect([$branchStep, $warehouseStep, $productStep, $financialYearStep])->filter(fn ($step) => $step['required'] && $step['status'] !== 'completed')->pluck('key')->values()->all();
        $stockLines = $this->stockLineCount($businessId);
        $stockProducts = $this->productBase($businessId)->where('product_type', '!=', 'service')->count();

        if (!empty($blocked)) {
            return $this->step('opening_stock', 14, 'Opening Stock', 'Post opening balances through stock ledger entries.', 'inventory', true, 'blocked', 'Opening stock is locked until branch, warehouse, product and financial-year setup are ready.', $stockLines, 'Complete Previous Step', $this->firstBlockedUrl($blocked, [$branchStep, $warehouseStep, $productStep, $financialYearStep]), $blocked);
        }

        if ($stockProducts === 0) {
            return $this->step('opening_stock', 14, 'Opening Stock', 'Post opening balances through stock ledger entries.', 'inventory', true, 'pending', 'Inventory products have not been created yet.', 0, 'Add Product', $routes['products']['url']);
        }

        $status = $stockLines > 0 ? 'completed' : 'attention_required';
        $detail = $stockLines > 0 ? $this->plural($stockLines, 'stock line') . ' configured through the stock ledger.' : 'Products exist, but no opening stock ledger decision has been posted.';
        return $this->step('opening_stock', 14, 'Opening Stock', 'Post opening balances through stock ledger entries.', 'inventory', true, $status, $detail, $stockLines, $stockLines ? 'Review Opening Stock' : 'Enter Opening Stock', $routes['opening_stock']['url']);
    }

    private function paymentModeStep(int $businessId, array $routes): array
    {
        $count = Schema::hasTable('payment_methods')
            ? DB::table('payment_methods')->where(fn ($q) => $q->whereNull('business_id')->orWhere('business_id', $businessId))->where('status', 'active')->count()
            : 0;

        return $this->step('payment_modes', 15, 'Payment Modes', 'Enable at least one collection mode such as Cash, UPI, Card or Bank.', 'billing', true, $count ? 'completed' : 'pending', $count ? $this->plural($count, 'payment mode') . ' available.' : 'No active payment modes found.', $count, $count ? 'Manage Payment Modes' : 'Add Payment Mode', $routes['masters']['url'] . '?tab=payment_methods');
    }

    private function accountStep(int $businessId, array $financialYearStep, array $gstStep, array $paymentModeStep, array $routes): array
    {
        $cash = $this->accountCount($businessId, ['cash']);
        $bank = $this->accountCount($businessId, ['bank']);
        $total = $this->tableCount('accounts', $businessId);
        $blocked = collect([$financialYearStep, $gstStep, $paymentModeStep])->filter(fn ($step) => $step['required'] && $step['status'] !== 'completed')->pluck('key')->values()->all();

        if (!empty($blocked)) {
            return $this->step('cash_bank_accounts', 16, 'Cash and Bank Accounts', 'Map payment modes to cash and bank ledgers.', 'accounts', true, 'blocked', 'Complete financial year, GST and payment modes before validating ledgers.', $total, 'Complete Previous Step', $this->firstBlockedUrl($blocked, [$financialYearStep, $gstStep, $paymentModeStep]), $blocked);
        }

        $status = $cash > 0 ? ($bank > 0 ? 'completed' : 'attention_required') : 'pending';
        $detail = $cash > 0 ? ($bank > 0 ? 'Cash and bank ledgers are configured.' : 'Cash ledger exists, but no bank ledger was found.') : 'No cash ledger was found.';
        return $this->step('cash_bank_accounts', 16, 'Cash and Bank Accounts', 'Map payment modes to cash and bank ledgers.', 'accounts', true, $status, $detail, $total, 'Manage Ledgers', $routes['ledgers']['url']);
    }

    private function employeeStep(int $businessId, array $permissions, array $routes): array
    {
        if (!$permissions['employees.view']) {
            return $this->step('employees', 17, 'Employees', 'Add team members when your business has staff users.', 'team', false, 'skipped', 'Hidden because your user cannot manage employees.', 0, 'Manage Employees', null);
        }

        $count = Schema::hasTable('users') ? DB::table('users')->where('tenant_id', $businessId)->where('role_id', 3)->where('status', 'active')->count() : 0;
        return $this->step('employees', 17, 'Employees', 'Add team members when your business has staff users.', 'team', false, $count ? 'completed' : 'optional', $count ? $this->plural($count, 'active staff user') . ' configured.' : 'Optional for owner-only businesses.', $count, $count ? 'Manage Employees' : 'Add Employee', $routes['employees']['url']);
    }

    private function roleStep(int $businessId, array $employeeStep, array $routes): array
    {
        $invalid = Schema::hasTable('users') ? DB::table('users')->where('tenant_id', $businessId)->where('status', 'active')->whereNull('role_id')->count() : 0;
        $staff = (int) ($employeeStep['record_count'] ?? 0);
        $status = $invalid ? 'attention_required' : ($staff ? 'completed' : 'optional');
        $detail = $invalid ? $invalid . ' active users have no role assignment.' : ($staff ? 'All active staff users have role assignments.' : 'Optional for owner-only businesses.');
        return $this->step('roles_permissions', 18, 'Roles and Permissions', 'Keep active users attached to valid role assignments.', 'team', false, $status, $detail, $staff, $staff ? 'Review Roles' : 'Review Users', $routes['users']['url']);
    }

    private function invoiceTemplateStep(array $routes): array
    {
        $hasTable = Schema::hasTable('invoice_templates') || Schema::hasTable('business_invoice_templates');
        return $this->step('invoice_template', 19, 'Invoice Template', 'Select invoice print styling and optional footer details.', 'billing', false, $hasTable ? 'pending' : 'coming_soon', $hasTable ? 'Invoice template setup is available but no selected template was detected.' : 'Dedicated invoice template setup is not available in this build yet.', 0, 'Configure Invoice Template', $routes['settings.invoice']['url']);
    }

    private function firstSaleStep(int $businessId, array $steps, array $routes): array
    {
        $required = ['business_profile', 'financial_year', 'gst', 'invoice_numbering', 'branch', 'products', 'payment_modes'];
        $blocked = collect($steps)->filter(fn ($step) => in_array($step['key'], $required, true) && $step['status'] !== 'completed')->pluck('key')->values()->all();
        $count = $this->tableCount('sales_vouchers', $businessId);

        if (!empty($blocked)) {
            return $this->step('first_sale', 20, 'First Test Sale', 'Create the first real invoice only after setup is ready.', 'billing', true, 'blocked', 'Complete the required setup steps before creating your first sale.', $count, 'Complete Previous Step', $this->firstBlockedUrl($blocked, $steps), $blocked);
        }

        return $this->step('first_sale', 20, 'First Test Sale', 'Create the first real invoice only after setup is ready.', 'billing', true, $count ? 'completed' : 'pending', $count ? $this->plural($count, 'sale invoice') . ' created.' : 'No sale invoice exists yet.', $count, $count ? 'Review Sales' : 'Create First Sale', $routes['sales.create']['url']);
    }

    private function getProgress(Collection $steps): array
    {
        $required = $steps->where('required', true)->whereNotIn('status', ['coming_soon', 'skipped']);
        $optional = $steps->where('required', false)->whereNotIn('status', ['coming_soon', 'skipped']);
        $requiredCompleted = $required->where('status', 'completed')->count();
        $requiredTotal = max($required->count(), 1);

        return [
            'required_completed' => $requiredCompleted,
            'required_total' => $required->count(),
            'optional_completed' => $optional->where('status', 'completed')->count(),
            'optional_total' => $optional->count(),
            'progress_percentage' => (int) round(($requiredCompleted / $requiredTotal) * 100),
        ];
    }

    private function getReadiness(Collection $steps): array
    {
        return [
            'billing' => $this->readinessGroup('Billing Readiness', $steps, ['business_profile', 'financial_year', 'gst', 'invoice_numbering', 'branch', 'products', 'payment_modes']),
            'inventory' => $this->readinessGroup('Inventory Readiness', $steps, ['branch', 'warehouse', 'products', 'units', 'opening_stock']),
            'accounting' => $this->readinessGroup('Accounting Readiness', $steps, ['financial_year', 'gst', 'cash_bank_accounts', 'invoice_numbering', 'payment_modes']),
        ];
    }

    private function readinessGroup(string $label, Collection $steps, array $keys): array
    {
        $group = $steps->whereIn('key', $keys);
        $attention = $group->whereIn('status', ['attention_required', 'blocked'])->count() > 0;
        $ready = $group->every(fn ($step) => $step['status'] === 'completed');

        return [
            'label' => $label,
            'status' => $ready ? 'ready' : ($attention ? 'attention_required' : 'not_ready'),
            'completed' => $group->where('status', 'completed')->count(),
            'total' => $group->count(),
        ];
    }

    private function getNextRecommendedStep(Collection $steps, array $routes): ?array
    {
        $step = $steps->first(fn ($item) => $item['required'] && !in_array($item['status'], ['completed', 'coming_soon', 'skipped'], true));
        return $step ? [
            'key' => $step['key'],
            'title' => $step['title'],
            'description' => $step['detail'],
            'action_label' => $step['action_label'],
            'action_url' => $step['action_url'],
            'status' => $step['status'],
        ] : [
            'key' => 'done',
            'title' => 'Setup is complete',
            'description' => 'Billing, inventory and accounting prerequisites are ready.',
            'action_label' => 'Open Business Dashboard',
            'action_url' => $routes['business.dashboard']['url'],
            'status' => 'completed',
        ];
    }

    private function summary(array $progress, array $readiness): array
    {
        $remaining = max(0, $progress['required_total'] - $progress['required_completed']);

        return [
            ['key' => 'setup_progress', 'label' => 'Setup Progress', 'value' => $progress['progress_percentage'] . '%', 'detail' => $progress['required_completed'] . ' of ' . $progress['required_total'] . ' required steps completed'],
            ['key' => 'remaining', 'label' => 'Required Steps Remaining', 'value' => $remaining, 'detail' => $remaining ? 'Finish these before go-live' : 'No required steps remaining'],
            ['key' => 'billing', 'label' => 'Ready for Billing', 'value' => $this->readinessLabel($readiness['billing']['status']), 'detail' => $readiness['billing']['completed'] . ' of ' . $readiness['billing']['total'] . ' dependencies complete'],
            ['key' => 'inventory', 'label' => 'Ready for Inventory', 'value' => $this->readinessLabel($readiness['inventory']['status']), 'detail' => $readiness['inventory']['completed'] . ' of ' . $readiness['inventory']['total'] . ' dependencies complete'],
            ['key' => 'accounting', 'label' => 'Ready for Accounting', 'value' => $this->readinessLabel($readiness['accounting']['status']), 'detail' => $readiness['accounting']['completed'] . ' of ' . $readiness['accounting']['total'] . ' dependencies complete'],
        ];
    }

    private function filterSteps(Collection $steps, string $filter): Collection
    {
        return match ($filter) {
            'required' => $steps->where('required', true),
            'completed' => $steps->where('status', 'completed'),
            'pending' => $steps->filter(fn ($step) => in_array($step['status'], ['pending', 'attention_required', 'blocked'], true)),
            'masters', 'inventory', 'billing', 'accounts', 'team' => $steps->where('category', $filter),
            default => $steps,
        };
    }

    private function permissions(User $user): array
    {
        $admin = in_array((int) ($user->role_id ?? 0), [1, 2], true);
        return [
            'onboarding.view' => $admin,
            'onboarding.manage' => $admin,
            'masters.manage' => $admin && AppController::canOpen('masters'),
            'employees.view' => $admin && AppController::canOpen('employees'),
            'employees.create' => $admin && AppController::canOpen('employees'),
            'sales.create' => $admin && AppController::canOpen('pos'),
            'accounting.create' => $admin && AppController::canOpen('vouchers'),
            'reports.view' => $admin && AppController::canOpen('reports'),
        ];
    }

    private function routes(): array
    {
        return [
            'onboarding' => $this->routeInfo('app.admin.onboarding'),
            'staff.workspace' => $this->routeInfo('app.staff.workspace'),
            'admin.workspace' => $this->routeInfo('app.admin.workspace'),
            'business.dashboard' => $this->routeInfo(Route::has('business.dashboard') ? 'business.dashboard' : 'app.dashboard'),
            'settings.business' => $this->routeInfo('app.setup.settings', ['section' => 'business']),
            'settings.gst' => $this->routeInfo('app.accounting.gst'),
            'settings.invoice' => $this->routeInfo('app.setup.settings', ['section' => 'invoice']),
            'settings.number_sequences' => $this->routeInfo('app.setup.settings', ['section' => 'numbering']),
            'financial_years' => $this->routeInfo('app.accounting.chart-of-accounts', ['section' => 'periods']),
            'branches' => $this->routeInfo('app.setup.branches'),
            'warehouses' => $this->routeInfo('app.warehouse.warehouses'),
            'masters' => $this->routeInfo('app.setup.masters'),
            'products' => $this->routeInfo('app.inventory.products'),
            'customers' => $this->routeInfo('app.sales.customers'),
            'suppliers' => $this->routeInfo('app.purchase.suppliers'),
            'opening_stock' => $this->routeInfo('app.inventory.opening-stock'),
            'ledgers' => $this->routeInfo('app.accounting.ledgers'),
            'employees' => $this->routeInfo('app.setup.employees'),
            'users' => $this->routeInfo('app.setup.users'),
            'sales.create' => $this->routeInfo('app.sales.pos'),
            'sales.index' => $this->routeInfo('app.sales.invoices'),
            'accounting.vouchers.create' => $this->routeInfo('app.accounting.vouchers', ['action' => 'create']),
            'reports.index' => $this->routeInfo('app.reports.business'),
            'profile.edit' => $this->routeInfo(Route::has('profile.edit') ? 'profile.edit' : 'app.dashboard'),
            'logout' => $this->routeInfo('logout'),
        ];
    }

    private function business(int $businessId): ?object
    {
        return Schema::hasTable('companies') ? DB::table('companies')->where('id', $businessId)->first() : null;
    }

    private function businessContext(?object $business, int $businessId): array
    {
        return [
            'id' => $businessId,
            'name' => $business->name ?? 'Current Business',
            'financial_year' => $business->financial_year ?? config('app.financial_year', '2026-27'),
            'branch' => Schema::hasTable('branches') ? DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('id')->value('name') : null,
        ];
    }

    private function productBase(int $businessId)
    {
        return DB::table('products')
            ->when(Schema::hasColumn('products', 'status'), fn ($q) => $q->where('status', 'active'))
            ->where(function ($query) use ($businessId) {
                if (Schema::hasColumn('products', 'business_id')) {
                    $query->where('business_id', $businessId);
                }

                if (Schema::hasColumn('products', 'company_id')) {
                    $method = Schema::hasColumn('products', 'business_id') ? 'orWhere' : 'where';
                    $query->{$method}('company_id', $businessId);
                }
            });
    }

    private function validProducts(int $businessId): int
    {
        if (!Schema::hasTable('products')) {
            return 0;
        }

        $query = $this->productBase($businessId)
            ->whereNotNull('name')
            ->whereNotNull('sku')
            ->where(function ($q) {
                if (Schema::hasColumn('products', 'unit_id')) {
                    $q->whereNotNull('unit_id');
                } elseif (Schema::hasColumn('products', 'unit')) {
                    $q->whereNotNull('unit');
                }
            });

        if (Schema::hasColumn('products', 'gst_rate')) {
            $query->whereNotNull('gst_rate');
        }

        if (Schema::hasColumn('products', 'hsn_code')) {
            $query->where(fn ($q) => $q->whereNotNull('hsn_code')->orWhereNotNull('hsn'));
        } elseif (Schema::hasColumn('products', 'hsn')) {
            $query->whereNotNull('hsn');
        }

        $priceColumns = array_values(array_filter(['selling_price', 'sale_price', 'default_selling_price'], fn ($column) => Schema::hasColumn('products', $column)));
        if ($priceColumns) {
            $query->where(function ($q) use ($priceColumns) {
                foreach ($priceColumns as $column) {
                    $q->orWhere($column, '>', 0);
                }
            });
        }

        return $query->count();
    }

    private function stockLineCount(int $businessId): int
    {
        if (!Schema::hasTable('stock_ledgers')) {
            return 0;
        }

        return DB::table('stock_ledgers')
            ->where('business_id', $businessId)
            ->where('transaction_type', 'opening_stock')
            ->selectRaw('product_id, branch_id, warehouse_id, COALESCE(SUM(quantity_in), 0) - COALESCE(SUM(quantity_out), 0) as quantity_on_hand')
            ->groupBy('product_id', 'branch_id', 'warehouse_id')
            ->havingRaw('quantity_on_hand <> 0')
            ->get()
            ->count();
    }

    private function activeCount(string $table, int $businessId, bool $includeGlobal = false): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)
            ->when(Schema::hasColumn($table, 'status'), fn ($q) => $q->where('status', 'active'))
            ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->where(function ($query) use ($table, $businessId, $includeGlobal) {
                if (Schema::hasColumn($table, 'business_id')) {
                    $includeGlobal ? $query->whereNull('business_id')->orWhere('business_id', $businessId) : $query->where('business_id', $businessId);
                } elseif (Schema::hasColumn($table, 'company_id')) {
                    $query->where('company_id', $businessId);
                }
            })
            ->count();
    }

    private function globalActiveCount(string $table): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)
            ->when(Schema::hasColumn($table, 'status'), fn ($q) => $q->where('status', 'active'))
            ->count();
    }

    private function tableCount(string $table, int $businessId): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        return DB::table($table)
            ->when(Schema::hasColumn($table, 'business_id'), fn ($q) => $q->where('business_id', $businessId))
            ->when(!Schema::hasColumn($table, 'business_id') && Schema::hasColumn($table, 'company_id'), fn ($q) => $q->where('company_id', $businessId))
            ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->count();
    }

    private function accountCount(int $businessId, array $types): int
    {
        if (!Schema::hasTable('accounts')) {
            return 0;
        }

        return DB::table('accounts')
            ->where('business_id', $businessId)
            ->when(Schema::hasColumn('accounts', 'status'), fn ($q) => $q->where('status', 'active'))
            ->where(function ($query) use ($types) {
                if (Schema::hasColumn('accounts', 'account_type')) {
                    $query->whereIn('account_type', $types);
                }

                if (Schema::hasColumn('accounts', 'account_code')) {
                    foreach ($types as $type) {
                        $query->orWhere('account_code', 'like', strtoupper($type) . '%');
                    }
                }
            })
            ->count();
    }

    private function step(string $key, int $number, string $title, string $description, string $category, bool $required, string $status, string $detail, int $recordCount, string $actionLabel, ?string $actionUrl, array $blockedBy = []): array
    {
        return [
            'key' => $key,
            'number' => $number,
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'required' => $required,
            'status' => $status,
            'status_label' => $this->label($status),
            'record_count' => $recordCount,
            'detail' => $detail,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'blocked_by' => $blockedBy,
        ];
    }

    private function firstBlockedUrl(array $blocked, array $steps): ?string
    {
        $first = collect($steps)->first(fn ($step) => in_array($step['key'], $blocked, true));
        return $first['action_url'] ?? null;
    }

    private function routeInfo(string $name, array $parameters = []): array
    {
        return ['name' => $name, 'url' => Route::has($name) ? route($name, $parameters, false) : null];
    }

    private function readinessLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Yes',
            'attention_required' => 'Attention Required',
            default => 'No',
        };
    }

    private function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }

    private function plural(int $count, string $label): string
    {
        return $count . ' ' . $label . ($count === 1 ? '' : 's');
    }
}
