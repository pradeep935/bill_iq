<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseVoucher;
use App\Models\SalesVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        if ($redirect = AppController::guardPage('dashboard')) {
            return $redirect;
        }

        $businessId = AppController::businessId();
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        $todaySales = $this->salesTotal($businessId, $today);
        $yesterdaySales = $this->salesTotal($businessId, $yesterday);
        $todayPurchases = $this->purchaseTotal($businessId, $today);
        $gstPayable = max(0, $this->salesTaxTotal($businessId, $today) - $this->purchaseTaxTotal($businessId, $today));
        $stockValue = $this->stockValue($businessId);
        $todayActivity = $this->todayActivity($businessId, $today);

        return Inertia::render('Dashboard', [
            'page' => 'dashboard',
            'title' => 'Business Dashboard',
            'role_id' => AppController::roleId(),
            'stats' => [
                ['label' => 'Today Sales', 'value' => $this->money($todaySales), 'hint' => $this->salesHint($todaySales, $yesterdaySales)],
                ['label' => 'Today Purchase', 'value' => $this->money($todayPurchases), 'hint' => $todayActivity . ' records touched today'],
                ['label' => 'GST Payable', 'value' => $this->money($gstPayable), 'hint' => 'Today output minus input GST'],
                ['label' => 'Stock Value', 'value' => $this->money($stockValue), 'hint' => 'Current stock x cost price'],
            ],
            'recentSales' => $this->recentSales($businessId),
        ]);
    }

    private function salesTotal(int $businessId, string $date): float
    {
        if (Schema::hasTable('sales_vouchers')) {
            return (float) SalesVoucher::query()
                ->where('business_id', $businessId)
                ->whereDate('invoice_date', $date)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->sum('grand_total');
        }

        if (!Schema::hasTable('sales')) {
            return 0;
        }

        return (float) DB::table('sales')
            ->where($this->businessColumn('sales'), $businessId)
            ->whereDate('invoice_date', $date)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->sum('total_amount');
    }

    private function purchaseTotal(int $businessId, string $date): float
    {
        if (Schema::hasTable('purchase_vouchers')) {
            return (float) PurchaseVoucher::query()
                ->where('business_id', $businessId)
                ->whereDate('purchase_date', $date)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->sum('grand_total');
        }

        if (!Schema::hasTable('purchases')) {
            return 0;
        }

        return (float) DB::table('purchases')
            ->where($this->businessColumn('purchases'), $businessId)
            ->whereDate('bill_date', $date)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->sum('total_amount');
    }

    private function salesTaxTotal(int $businessId, string $date): float
    {
        if (Schema::hasTable('sales_vouchers')) {
            return (float) SalesVoucher::query()
                ->where('business_id', $businessId)
                ->whereDate('invoice_date', $date)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->sum(DB::raw('cgst_amount + sgst_amount + igst_amount + cess_amount'));
        }

        if (!Schema::hasTable('sales')) {
            return 0;
        }

        return (float) DB::table('sales')
            ->where($this->businessColumn('sales'), $businessId)
            ->whereDate('invoice_date', $date)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->sum(DB::raw('cgst_amount + sgst_amount + igst_amount'));
    }

    private function purchaseTaxTotal(int $businessId, string $date): float
    {
        if (Schema::hasTable('purchase_vouchers')) {
            return (float) PurchaseVoucher::query()
                ->where('business_id', $businessId)
                ->whereDate('purchase_date', $date)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->sum(DB::raw('cgst_amount + sgst_amount + igst_amount + cess_amount'));
        }

        if (!Schema::hasTable('purchases')) {
            return 0;
        }

        return (float) DB::table('purchases')
            ->where($this->businessColumn('purchases'), $businessId)
            ->whereDate('bill_date', $date)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->sum(DB::raw('cgst_amount + sgst_amount + igst_amount'));
    }

    private function stockValue(int $businessId): float
    {
        if (!Schema::hasTable('products')) {
            return 0;
        }

        $businessColumn = Schema::hasColumn('products', 'business_id') ? 'business_id' : 'company_id';
        $stockColumn = Schema::hasColumn('products', 'current_stock') ? 'current_stock' : 'opening_stock';
        $costColumn = Schema::hasColumn('products', 'cost_price') ? 'cost_price' : (Schema::hasColumn('products', 'purchase_price') ? 'purchase_price' : null);

        if (!Schema::hasColumn('products', $businessColumn) || !Schema::hasColumn('products', $stockColumn) || !$costColumn) {
            return 0;
        }

        return (float) Product::query()
            ->where($businessColumn, $businessId)
            ->when(Schema::hasColumn('products', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->sum(DB::raw('COALESCE(' . $stockColumn . ', 0) * COALESCE(' . $costColumn . ', 0)'));
    }

    private function todayActivity(int $businessId, string $date): int
    {
        $tables = [
            'sales_vouchers' => $this->businessColumn('sales_vouchers'),
            'sales' => $this->businessColumn('sales'),
            'purchase_vouchers' => $this->businessColumn('purchase_vouchers'),
            'purchases' => $this->businessColumn('purchases'),
            'products' => $this->businessColumn('products'),
            'customers' => $this->businessColumn('customers'),
            'suppliers' => $this->businessColumn('suppliers'),
        ];

        return collect($tables)->reduce(function (int $total, string $businessColumn, string $table) use ($businessId, $date) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $businessColumn) || !Schema::hasColumn($table, 'updated_at')) {
                return $total;
            }

            return $total + DB::table($table)
                ->where($businessColumn, $businessId)
                ->whereDate('updated_at', $date)
                ->count();
        }, 0);
    }

    private function recentSales(int $businessId): array
    {
        if (Schema::hasTable('sales_vouchers')) {
            return SalesVoucher::query()
                ->where('business_id', $businessId)
                ->whereNotIn('status', ['cancelled', 'reversed'])
                ->latest('invoice_date')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (SalesVoucher $sale) => [
                    'invoice' => $sale->invoice_number,
                    'customer' => $sale->customer_name_snapshot ?: 'Walk-in Customer',
                    'total' => $this->money((float) $sale->grand_total),
                    'payment' => ucfirst(str_replace('_', ' ', $sale->payment_status ?: $sale->sale_type)),
                ])
                ->all();
        }

        if (!Schema::hasTable('sales')) {
            return [];
        }

        return DB::table('sales')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.' . $this->businessColumn('sales'), $businessId)
            ->whereNotIn('sales.status', ['cancelled', 'reversed'])
            ->latest('invoice_date')
            ->latest('sales.id')
            ->limit(5)
            ->get()
            ->map(fn ($sale) => [
                'invoice' => $sale->invoice_no,
                'customer' => $sale->name ?: 'Walk-in Customer',
                'total' => $this->money((float) $sale->total_amount),
                'payment' => ucfirst(str_replace('_', ' ', $sale->payment_mode ?: $sale->sale_type)),
            ])
            ->all();
    }

    private function businessColumn(string $table): string
    {
        if (!Schema::hasTable($table)) {
            return 'business_id';
        }

        foreach (['business_id', 'tenant_id', 'company_id'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return 'business_id';
    }

    private function salesHint(float $todaySales, float $yesterdaySales): string
    {
        if ($yesterdaySales <= 0) {
            return $todaySales > 0 ? 'New sales today' : 'No sales recorded today';
        }

        $change = (($todaySales - $yesterdaySales) / $yesterdaySales) * 100;
        $prefix = $change >= 0 ? '+' : '';

        return $prefix . number_format($change, 1) . '% vs yesterday';
    }

    private function money(float $amount): string
    {
        return '₹' . number_format($amount, 2);
    }
}
