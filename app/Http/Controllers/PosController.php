<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\SalesVoucher;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

class PosController extends Controller
{
    public function index()
    {
        if ($redirect = AppController::guardPage('pos')) {
            return $redirect;
        }

        return Inertia::render('Pos', [
            'page' => 'pos',
            'title' => 'POS Billing',
            'role_id' => AppController::roleId(),
            'context' => AppController::context(),
            'endpoints' => $this->endpoints(),
            'pos' => $this->payload(),
        ]);
    }

    private function endpoints(): array
    {
        $names = [
            'store' => 'app.sales.invoices.store',
            'references' => 'app.sales.invoices.references',
            'productSearch' => 'app.sales.invoices.products.search',
            'productScan' => 'app.sales.invoices.products.scan',
            'show' => 'app.sales.invoices.show',
            'print' => 'app.sales.invoices.print',
            'contextSwitch' => 'app.context.switch',
        ];

        return collect($names)
            ->filter(fn ($name) => Route::has($name))
            ->map(fn ($name) => route($name, in_array($name, ['app.sales.invoices.print', 'app.sales.invoices.show'], true) ? ['sale' => '__ID__'] : [], false))
            ->all();
    }

    private function payload(): array
    {
        $businessId = AppController::businessId();
        $branchId = AppController::branchId();
        $stock = app(StockService::class);

        $categories = ProductCategory::query()
            ->where(function ($query) use ($businessId) {
                $query->whereNull('business_id')->orWhere('business_id', $businessId);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name'])
            ->values();

        $recentProductIds = DB::table('sales_items')
            ->join('sales_vouchers', 'sales_vouchers.id', '=', 'sales_items.sales_voucher_id')
            ->where('sales_vouchers.business_id', $businessId)
            ->when($branchId, fn ($query) => $query->where('sales_vouchers.branch_id', $branchId))
            ->whereIn('sales_vouchers.status', ['confirmed', 'approved'])
            ->select('sales_items.product_id', DB::raw('MAX(sales_items.id) as latest_id'))
            ->groupBy('sales_items.product_id')
            ->orderByDesc('latest_id')
            ->limit(8)
            ->pluck('sales_items.product_id');

        $products = Product::query()
            ->with('images')
            ->where(function ($query) use ($businessId) {
                $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->whereIn('id', $recentProductIds)
            ->get()
            ->keyBy('id');

        $recent = $recentProductIds
            ->map(function ($id) use ($products, $businessId, $branchId, $stock) {
                $product = $products->get($id);
                if (!$product) {
                    return null;
                }

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcode' => $product->primary_barcode ?: $product->barcode,
                    'image_url' => $this->imageUrl(optional($product->images->sortByDesc('is_primary')->first())->image_path),
                    'unit_id' => $product->unit_id,
                    'selling_rate' => (float) ($product->selling_price ?: $product->sale_price ?: $product->default_selling_price),
                    'mrp' => $product->mrp !== null ? (float) $product->mrp : null,
                    'gst_rate' => (float) $product->gst_rate,
                    'cess_rate' => (float) $product->cess_rate,
                    'product_type' => $product->product_type,
                    'item_type' => $product->item_type,
                    'available_stock' => $product->product_type === 'service' ? null : $stock->getCurrentStock([
                        'business_id' => $businessId,
                        'branch_id' => $branchId,
                        'product_id' => $product->id,
                    ]),
                    'batches' => [],
                ];
            })
            ->filter()
            ->values();

        $heldBills = SalesVoucher::query()
            ->with('customer')
            ->where('business_id', $businessId)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->where('status', 'hold')
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (SalesVoucher $voucher) => [
                'id' => $voucher->id,
                'invoice_number' => $voucher->invoice_number,
                'customer' => $voucher->customer_name_snapshot ?: optional($voucher->customer)->customer_name ?: 'Walk-in Customer',
                'grand_total' => (float) $voucher->grand_total,
                'created_at' => optional($voucher->created_at)->format('Y-m-d H:i'),
            ])
            ->values();

        return [
            'categories' => $categories,
            'recent_products' => $recent,
            'held_bills' => $heldBills,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        if (is_file(public_path($path))) {
            return asset($path);
        }

        if (is_file(storage_path('app/public/' . ltrim($path, '/')))) {
            return asset('storage/' . ltrim($path, '/'));
        }

        return asset($path);
    }
}
