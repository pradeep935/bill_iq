<?php

namespace App\Support;

/**
 * Logins limited to one department. Everything is closed to them except what is listed here,
 * so a screen or address added later stays closed until it is listed.
 */
class DepartmentRole
{
    public const SALES = 4;
    public const PURCHASE = 5;
    public const ALL = [self::SALES, self::PURCHASE];

    private const LABELS = [self::SALES => 'Sales', self::PURCHASE => 'Purchase'];

    private const HOME = [self::SALES => '/app/sales/pos', self::PURCHASE => '/app/purchase/bills'];

    private const PAGES = [
        self::SALES => ['pos', 'sales', 'sales-returns', 'customers', 'payment-reminders', 'inventory-outward', 'inventory-reserved', 'inventory-current-stock', 'stock-summary', 'profile'],
        self::PURCHASE => ['purchases', 'supplier-bills', 'purchase-returns', 'suppliers', 'inventory-inward', 'inventory-reorder', 'inventory-orders', 'products', 'inventory-current-stock', 'stock-summary', 'profile'],
    ];

    /** [methods, path pattern] */
    private const COMMON = [
        ['GET', '/|login|logout|up|profile|app/search|app/logout|storage/.*|i/.*|q/.*'],
        ['POST', 'login|logout|app/context|upload/photo'],
    ];

    private const ALLOWED = [
        self::SALES => [
            ['GET|POST|PUT|PATCH|DELETE', 'app/sales/.*'],
            ['GET', 'app/inventory/current-stock(/.*)?'],
        ],
        self::PURCHASE => [
            ['GET|POST|PUT|PATCH|DELETE', 'app/purchase/.*'],
            ['GET', 'app/purchases|app/inventory/current-stock(/.*)?|app/inventory/products(/.*)?|app/inventory/hsn-search|app/inventory/hsn-master/reference-search'],
            // Adding and correcting products, not deleting them.
            ['POST', 'app/inventory/products(/save)?'],
            ['PUT|PATCH', 'app/inventory/products/\d+'],
        ],
    ];

    public static function is(?int $roleId): bool
    {
        return in_array((int) $roleId, self::ALL, true);
    }

    public static function label(int $roleId): ?string
    {
        return self::LABELS[$roleId] ?? null;
    }

    public static function home(int $roleId): string
    {
        return self::HOME[$roleId];
    }

    public static function pages(int $roleId): array
    {
        return self::PAGES[$roleId] ?? [];
    }

    public static function allows(int $roleId, string $method, string $path): bool
    {
        $method = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $path = trim($path, '/') ?: '/';

        foreach (array_merge(self::COMMON, self::ALLOWED[$roleId] ?? []) as [$methods, $pattern]) {
            if (in_array($method, explode('|', $methods), true) && preg_match('#^(?:' . $pattern . ')$#', $path)) {
                return true;
            }
        }

        return false;
    }
}
