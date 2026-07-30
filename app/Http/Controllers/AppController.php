<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;

class AppController extends Controller
{
    public static function roleId(): int
    {
        return (int) (Auth::user()->role_id ?? 2);
    }

    public static function businessId(): int
    {
        $businessId = session('business_id')
            ?: session('company_id')
            ?: session('tenant_id');

        if ($businessId) {
            return (int) $businessId;
        }

        $user = Auth::user();

        if ($user && $user->tenant_id) {
            return (int) $user->tenant_id;
        }

        if (Schema::hasTable('companies')) {
            $companyId = DB::table('companies')->value('id');

            if ($companyId) {
                return (int) $companyId;
            }

            return (int) DB::table('companies')->insertGetId([
                'name' => 'ABC Retail Pvt Ltd',
                'state' => 'Noida',
                'financial_year' => '2026-27',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return 1;
    }

    public static function branchId(): ?int
    {
        $businessId = self::businessId();
        $branchId = session('branch_id') ?: optional(Auth::user())->branch_id;

        if ($branchId && Schema::hasTable('branches')) {
            $exists = DB::table('branches')
                ->where('business_id', $businessId)
                ->where('id', $branchId)
                ->exists();

            if ($exists) {
                return (int) $branchId;
            }
        }

        if (Schema::hasTable('branches')) {
            $fallback = DB::table('branches')
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->orderBy('id')
                ->value('id');

            if ($fallback) {
                session(['branch_id' => (int) $fallback]);
                return (int) $fallback;
            }
        }

        return null;
    }

    public static function financialYearId(): ?int
    {
        return session('financial_year_id') ? (int) session('financial_year_id') : null;
    }

    public static function financialYear(): string
    {
        if ($year = session('financial_year')) {
            return (string) $year;
        }

        if (Schema::hasTable('financial_years')) {
            $year = DB::table('financial_years')
                ->where('business_id', self::businessId())
                ->where('status', 'active')
                ->orderByDesc('id')
                ->first();

            if ($year) {
                session([
                    'financial_year_id' => $year->id,
                    'financial_year' => $year->name ?? $year->financial_year ?? $year->year_name ?? 'Current',
                ]);

                return (string) session('financial_year');
            }
        }

        $companyYear = Schema::hasTable('companies')
            ? DB::table('companies')->where('id', self::businessId())->value('financial_year')
            : null;

        return (string) ($companyYear ?: '2026-27');
    }

    public static function tenantScope(): array
    {
        return [
            'business_id' => self::businessId(),
            'branch_id' => self::branchId(),
            'financial_year_id' => self::financialYearId(),
        ];
    }

    public static function applyTenantScope($query, ?string $table = null, bool $branchScoped = true)
    {
        $model = method_exists($query, 'getModel') ? $query->getModel() : null;
        $tableName = $table ?: ($model ? $model->getTable() : null);
        $prefix = $tableName ? $tableName . '.' : '';

        if (!$tableName || !Schema::hasTable($tableName)) {
            return $query;
        }

        if (Schema::hasColumn($tableName, 'business_id')) {
            $query->where($prefix . 'business_id', self::businessId());
        }

        if ($branchScoped && Schema::hasColumn($tableName, 'branch_id') && self::branchId()) {
            $query->where($prefix . 'branch_id', self::branchId());
        }

        if (Schema::hasColumn($tableName, 'financial_year_id') && self::financialYearId()) {
            $query->where($prefix . 'financial_year_id', self::financialYearId());
        }

        return $query;
    }

    public static function context(): array
    {
        $businessId = self::businessId();
        $branchId = self::branchId();
        $businesses = Schema::hasTable('companies')
            ? DB::table('companies')->orderBy('name')->get(['id', 'name', 'state', 'financial_year'])
            : collect();
        $business = Schema::hasTable('companies') ? DB::table('companies')->where('id', $businessId)->first() : null;
        $branches = Schema::hasTable('branches')
            ? DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code', 'state'])
            : collect();
        $branch = $branches->firstWhere('id', $branchId);
        $financialYears = Schema::hasTable('financial_years')
            ? DB::table('financial_years')->where('business_id', $businessId)->orderByDesc('id')->get()->map(fn ($year) => [
                'id' => $year->id,
                'name' => $year->name ?? $year->financial_year ?? $year->year_name ?? 'Current',
            ])->values()
            : collect([['id' => self::financialYearId(), 'name' => self::financialYear()]]);
        $setting = fn ($column, $fallback = null) => $business && property_exists($business, $column) && $business->{$column} !== null
            ? $business->{$column}
            : $fallback;

        return [
            'tenant_scope' => self::tenantScope(),
            'business' => [
                'id' => $businessId,
                'name' => $business->name ?? 'BillIQ',
                'state' => $business->state ?? null,
            ],
            'branch' => [
                'id' => $branchId,
                'name' => $branch->name ?? 'Primary Branch',
                'code' => $branch->code ?? null,
                'state' => $branch->state ?? null,
            ],
            'financial_year' => [
                'id' => self::financialYearId(),
                'name' => self::financialYear(),
            ],
            'businesses' => $businesses->map(fn ($item) => ['id' => $item->id, 'name' => $item->name])->values(),
            'branches' => $branches->map(fn ($item) => ['id' => $item->id, 'name' => $item->name, 'code' => $item->code])->values(),
            'financial_years' => $financialYears,
            'settings' => [
                'currency' => $setting('currency', config('app.currency', 'INR')),
                'currency_symbol' => $setting('currency_symbol', config('app.currency_symbol', '₹')),
                'locale' => $setting('locale', config('app.locale', 'en-IN')),
                'tax_label' => $setting('tax_label', config('app.tax_label', 'GST')),
                'invoice_prefix' => $setting('invoice_prefix', config('app.invoice_prefix', 'INV')),
            ],
        ];
    }

    public function switchContext(Request $request)
    {
        $data = $request->validate([
            'business_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'financial_year_id' => ['nullable', 'integer'],
            'financial_year' => ['nullable', 'string', 'max:30'],
        ]);

        $businessId = (int) ($data['business_id'] ?? self::businessId());

        if (Schema::hasTable('companies')) {
            abort_unless(DB::table('companies')->where('id', $businessId)->exists(), 422);
        }

        $session = ['business_id' => $businessId, 'company_id' => $businessId, 'tenant_id' => $businessId];

        if (!empty($data['branch_id']) && Schema::hasTable('branches')) {
            abort_unless(DB::table('branches')->where('business_id', $businessId)->where('id', $data['branch_id'])->exists(), 422);
            $session['branch_id'] = (int) $data['branch_id'];
        } elseif (Schema::hasTable('branches')) {
            $session['branch_id'] = DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('id')->value('id');
        }

        if (!empty($data['financial_year_id'])) {
            $session['financial_year_id'] = (int) $data['financial_year_id'];
        }
        if (!empty($data['financial_year'])) {
            $session['financial_year'] = $data['financial_year'];
        } else {
            $session['financial_year'] = null;
        }

        session($session);

        return back();
    }

    public static function roleHome(): string
    {
        switch (self::roleId()) {
            case 1:
                return '/app/admin/workspace';
            case 3:
                return '/app/staff/workspace';
            default:
                return '/app';
        }
    }

    public static function canOpen(string $page): bool
    {
        $allowedPages = [
            1 => null,
            2 => [
                'dashboard', 'crm', 'pos', 'sales', 'sales-returns', 'customers', 'inventory-outward', 'inventory-reserved',
                'purchases', 'purchase-returns', 'suppliers', 'inventory-inward', 'inventory-reorder', 'inventory-orders',
                'inventory', 'products', 'opening-stock', 'stock-summary', 'inventory-add', 'inventory-current-stock', 'inventory-vouchers',
                'inventory-batches', 'inventory-serials', 'inventory-barcode-center', 'inventory-manufacturing',
                'inventory-warehouses', 'inventory-bins', 'inventory-godown-balance', 'inventory-transfer',
                'inventory-transfer-requests', 'inventory-adjustment', 'inventory-audit', 'inventory-allocation',
                'accounts', 'vouchers', 'ledgers', 'expenses', 'fixed-assets', 'payroll', 'employees', 'gst', 'inventory-gst-returns',
                'reports', 'inventory-reports', 'stock-ledger', 'inventory-valuation', 'inventory-audit-trail',
                'acceptance', 'masters', 'branches',
            ],
            3 => ['staff-workspace', 'crm', 'pos', 'sales', 'customers', 'stock-summary', 'inventory-current-stock', 'inventory-reserved', 'stock-ledger', 'fixed-assets'],
        ];

        $roleAllowedPages = $allowedPages[self::roleId()] ?? null;
        return !is_array($roleAllowedPages) || in_array($page, $roleAllowedPages, true);
    }

    public static function guardPage(string $page)
    {
        if (!self::canOpen($page)) {
            return redirect(self::roleHome());
        }

        return null;
    }
}
