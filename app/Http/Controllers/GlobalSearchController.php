<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Top-bar search: invoices, customers and products of the current business, limited to what the role may open. */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json(['groups' => []]);

        $businessId = AppController::businessId();
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $groups = [];

        if (AppController::canOpen('sales')) {
            $rows = DB::table('sales_vouchers')->where('business_id', $businessId)
                ->where(fn ($w) => $w->where('invoice_number', 'like', $like)->orWhere('customer_name_snapshot', 'like', $like)->orWhere('customer_mobile_snapshot', 'like', $like))
                ->orderByDesc('invoice_date')->limit(5)->get(['id', 'invoice_number', 'customer_name_snapshot', 'grand_total', 'invoice_date']);
            $groups[] = ['label' => 'Invoices', 'items' => $rows->map(fn ($r) => [
                'title' => $r->invoice_number, 'sub' => trim(($r->customer_name_snapshot ?: '') . ' · Rs. ' . number_format((float) $r->grand_total, 2)),
                'href' => '/app/sales/invoices?search=' . urlencode($r->invoice_number),
            ])->all()];
        }
        if (AppController::canOpen('customers')) {
            $rows = DB::table('customers')->where('business_id', $businessId)->whereNull('deleted_at')
                ->where(fn ($w) => $w->where('customer_name', 'like', $like)->orWhere('mobile', 'like', $like)->orWhere('gstin', 'like', $like))
                ->orderBy('customer_name')->limit(5)->get(['id', 'customer_name', 'mobile', 'gstin']);
            $groups[] = ['label' => 'Customers', 'items' => $rows->map(fn ($r) => [
                'title' => $r->customer_name, 'sub' => trim(($r->mobile ?: '') . ' ' . ($r->gstin ?: '')),
                'href' => '/app/sales/customers?search=' . urlencode($r->customer_name),
            ])->all()];
        }
        if (AppController::canOpen('products')) {
            $rows = DB::table('products')->where('business_id', $businessId)->whereNull('deleted_at')
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('primary_barcode', 'like', $like)->orWhere('hsn_code', 'like', $like))
                ->orderBy('name')->limit(5)->get(['id', 'name', 'sku', 'current_stock']);
            $groups[] = ['label' => 'Products', 'items' => $rows->map(fn ($r) => [
                'title' => $r->name, 'sub' => trim(($r->sku ?: '') . ' · Stock ' . (float) $r->current_stock),
                'href' => '/app/inventory/products?search=' . urlencode($r->name),
            ])->all()];
        }

        return response()->json(['groups' => array_values(array_filter($groups, fn ($g) => $g['items']))]);
    }
}
