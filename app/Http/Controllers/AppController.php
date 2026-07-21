<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
                'acceptance',
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
