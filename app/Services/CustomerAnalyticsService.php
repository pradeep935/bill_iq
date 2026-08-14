<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesItem;
use App\Models\SalesVoucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerAnalyticsService
{
    public function __construct(private CustomerClassificationService $classifier)
    {
    }

    public function summary(Customer $customer): array
    {
        $base = $this->validSales($customer);
        $orders = (clone $base)->count();
        $lifetime = (float) (clone $base)->sum('grand_total');
        $firstPurchase = (clone $base)->min('invoice_date');
        $lastPurchase = (clone $base)->max('invoice_date');
        $status = $this->classifier->classify($customer);

        return [
            'total_orders' => $orders,
            'lifetime_sales' => round($lifetime, 2),
            'average_order_value' => $orders > 0 ? round($lifetime / $orders, 2) : 0,
            'first_purchase_date' => $firstPurchase,
            'last_purchase_date' => $lastPurchase,
            'total_paid' => round((float) (clone $base)->sum('paid_amount'), 2),
            'outstanding' => round((float) (clone $base)->sum('balance_amount'), 2),
            'customer_status' => $status,
            'customer_status_label' => $this->classifier->label($status),
            'most_purchased_products' => $this->productHistory($customer, 5),
        ];
    }

    public function insight(Customer $customer): array
    {
        $summary = $this->summary($customer);

        return [
            'customer' => [
                'id' => $customer->id,
                'customer_name' => $customer->customer_name,
                'mobile' => $customer->mobile,
                'whatsapp_number' => $customer->whatsapp_number ?: $customer->mobile,
                'gstin' => $customer->gstin,
            ],
            'summary' => $summary,
        ];
    }

    public function recentInvoices(Customer $customer, int $limit = 10): array
    {
        return SalesVoucher::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->latest('invoice_date')
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'invoice_number', 'invoice_date', 'grand_total', 'payment_status', 'status'])
            ->map(fn (SalesVoucher $invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => optional($invoice->invoice_date)->format('Y-m-d'),
                'grand_total' => (float) $invoice->grand_total,
                'payment_status' => $invoice->payment_status,
                'status' => $invoice->status,
            ])
            ->values()
            ->all();
    }

    public function productHistory(Customer $customer, int $limit = 20): array
    {
        return SalesItem::query()
            ->join('sales_vouchers', 'sales_vouchers.id', '=', 'sales_items.sales_voucher_id')
            ->where('sales_vouchers.business_id', $customer->business_id)
            ->where('sales_vouchers.customer_id', $customer->id)
            ->whereIn('sales_vouchers.status', CustomerClassificationService::VALID_SALE_STATUSES)
            ->groupBy('sales_items.product_id', 'sales_items.product_name_snapshot')
            ->orderByDesc(DB::raw('SUM(sales_items.quantity)'))
            ->limit($limit)
            ->get([
                'sales_items.product_id',
                'sales_items.product_name_snapshot as product',
                DB::raw('SUM(sales_items.quantity) as total_quantity'),
                DB::raw('COUNT(DISTINCT sales_vouchers.id) as purchase_count'),
                DB::raw('MAX(sales_vouchers.invoice_date) as last_purchase_date'),
                DB::raw('AVG(sales_items.selling_rate) as average_selling_price'),
            ])
            ->map(function ($row) use ($customer) {
                $last = $this->lastProductPurchase($customer, (int) $row->product_id);

                return [
                    'product_id' => $row->product_id,
                    'product' => $row->product,
                    'total_quantity' => (float) $row->total_quantity,
                    'purchase_count' => (int) $row->purchase_count,
                    'last_purchase_date' => $row->last_purchase_date,
                    'last_selling_price' => (float) ($last['selling_rate'] ?? 0),
                    'average_selling_price' => round((float) $row->average_selling_price, 2),
                ];
            })
            ->values()
            ->all();
    }

    public function lastProductPurchase(Customer $customer, int $productId): ?array
    {
        $row = SalesItem::query()
            ->join('sales_vouchers', 'sales_vouchers.id', '=', 'sales_items.sales_voucher_id')
            ->where('sales_vouchers.business_id', $customer->business_id)
            ->where('sales_vouchers.customer_id', $customer->id)
            ->where('sales_items.product_id', $productId)
            ->whereIn('sales_vouchers.status', CustomerClassificationService::VALID_SALE_STATUSES)
            ->latest('sales_vouchers.invoice_date')
            ->latest('sales_vouchers.id')
            ->first(['sales_vouchers.invoice_date', 'sales_items.selling_rate']);

        return $row ? [
            'invoice_date' => $row->invoice_date ? Carbon::parse($row->invoice_date)->format('Y-m-d') : null,
            'selling_rate' => (float) $row->selling_rate,
        ] : null;
    }

    private function validSales(Customer $customer)
    {
        return SalesVoucher::query()
            ->where('business_id', $customer->business_id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', CustomerClassificationService::VALID_SALE_STATUSES);
    }
}
