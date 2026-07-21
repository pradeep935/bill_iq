<?php

namespace App\Http\Controllers;

use App\Services\OpeningStockService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockSummaryController extends Controller
{
    private StockService $stock;
    private OpeningStockService $openingStock;

    public function __construct(StockService $stock, OpeningStockService $openingStock)
    {
        $this->stock = $stock;
        $this->openingStock = $openingStock;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('stock-summary')) {
            return $redirect;
        }

        return Inertia::render('Inventory/StockSummary', [
            'page' => 'stock-summary',
            'title' => 'Current Stock',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('stock-summary'), 403);

        $paginator = $this->stock->summary($request->all());

        return response()->json([
            'items' => $paginator->getCollection()->map(fn ($item) => [
                'business_id' => $item->business_id,
                'branch_id' => $item->branch_id,
                'warehouse_id' => $item->warehouse_id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'batch_id' => $item->batch_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'branch' => $item->branch_name,
                'warehouse' => $item->warehouse_name,
                'batch' => $item->batch_no,
                'expiry_date' => $item->expiry_date,
                'quantity_available' => (float) $item->quantity_available,
                'average_cost' => round((float) $item->average_cost, 2),
                'stock_value' => round((float) $item->stock_value, 2),
                'reorder_level' => (float) ($item->reorder_stock ?: $item->reorder_level ?: 0),
                'stock_status' => $item->stock_status,
            ])->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function references()
    {
        abort_unless(AppController::canOpen('stock-summary'), 403);

        return response()->json($this->openingStock->references());
    }
}
