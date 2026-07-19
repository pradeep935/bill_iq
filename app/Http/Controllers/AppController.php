<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class AppController extends Controller
{
    public static function roleId(): int
    {
        return (int) (Auth::user()->role_id ?? 2);
    }

    public static function roleHome(): string
    {
        return match (self::roleId()) {
            1 => '/app/admin/workspace',
            3 => '/app/staff/workspace',
            default => '/app',
        };
    }

    public static function canOpen(string $page): bool
    {
        $allowedPages = [
            1 => null,
            2 => [
                'dashboard', 'pos', 'sales', 'customers', 'inventory-outward', 'inventory-reserved',
                'purchases', 'suppliers', 'inventory-inward', 'inventory-reorder', 'inventory-orders',
                'inventory', 'products', 'inventory-add', 'inventory-current-stock', 'inventory-vouchers',
                'inventory-batches', 'inventory-serials', 'inventory-barcode-center', 'inventory-manufacturing',
                'inventory-warehouses', 'inventory-bins', 'inventory-godown-balance', 'inventory-transfer',
                'inventory-transfer-requests', 'inventory-adjustment', 'inventory-audit', 'inventory-allocation',
                'accounts', 'vouchers', 'ledgers', 'expenses', 'gst', 'inventory-gst-returns',
                'reports', 'inventory-reports', 'stock-ledger', 'inventory-valuation', 'inventory-audit-trail',
                'acceptance',
            ],
            3 => ['staff-workspace', 'pos', 'sales', 'customers', 'inventory-current-stock', 'inventory-reserved', 'stock-ledger'],
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
