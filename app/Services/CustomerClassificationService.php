<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesVoucher;

class CustomerClassificationService
{
    public const VALID_SALE_STATUSES = ['confirmed', 'approved'];

    public function classify(Customer $customer): string
    {
        $query = $this->validSales($customer);
        $orders = (clone $query)->count();

        if ($orders <= 0) {
            return 'new';
        }

        $lastPurchase = (clone $query)->max('invoice_date');
        $inactiveDays = (int) config('customer_crm.inactive_days', 180);

        if ($lastPurchase && now()->startOfDay()->diffInDays($lastPurchase, false) * -1 >= $inactiveDays) {
            return 'inactive';
        }

        $regularMinOrders = (int) config('customer_crm.regular_min_orders', 5);
        $regularPeriodDays = (int) config('customer_crm.regular_period_days', 90);
        $recentOrders = (clone $query)->whereDate('invoice_date', '>=', now()->subDays($regularPeriodDays)->toDateString())->count();

        if ($recentOrders >= $regularMinOrders) {
            return 'regular';
        }

        return $orders >= 2 ? 'repeat' : 'new';
    }

    public function label(string $status): string
    {
        return match ($status) {
            'regular' => 'Regular Customer',
            'repeat' => 'Repeat Customer',
            'inactive' => 'Inactive Customer',
            default => 'New Customer',
        };
    }

    private function validSales(Customer $customer)
    {
        return SalesVoucher::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', self::VALID_SALE_STATUSES);
    }
}
