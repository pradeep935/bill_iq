<?php

namespace App\Http\Controllers;

use App\Services\OpeningStockService;
use App\Services\StockService;
use App\Services\MasterDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockSummaryController extends Controller
{
    private StockService $stock;
    private OpeningStockService $openingStock;

    public function __construct(StockService $stock, OpeningStockService $openingStock, private MasterDataService $masters)
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
            'page' => 'inventory-current-stock',
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
                'view_mode' => $request->query('view_mode', 'summary'),
                'product_name' => $item->product_name,
                'image' => $item->image_path,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'hsn' => $item->hsn,
                'category' => $item->category_name,
                'brand' => $item->brand_name,
                'branch' => $item->branch_name,
                'warehouse' => $item->warehouse_name,
                'batch' => $item->batch_no,
                'branch_count' => (int) ($item->branch_count ?? 0),
                'batch_required' => (bool) ($item->batch_required ?? false),
                'serial_required' => (bool) ($item->serial_required ?? false),
                'expiry_date' => $item->expiry_date,
                'quantity_on_hand' => (float) $item->quantity_on_hand,
                'reserved_quantity' => (float) $item->reserved_quantity,
                'quantity_available' => (float) $item->quantity_available,
                'unit' => $item->unit ?: 'PCS',
                'average_cost' => round((float) $item->average_cost, 2),
                'stock_value' => round((float) $item->stock_value, 2),
                'reorder_level' => (float) ($item->reorder_stock ?: $item->reorder_level ?: 0),
                'last_updated' => $item->last_updated,
                'last_movement_date' => $item->last_updated,
                'created_by' => $item->created_by_id,
                'updated_by' => $item->updated_by_id,
                'stock_status' => $item->stock_status,
            ])->values(),
            'dashboard' => $this->stock->dashboard($request->all()),
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

        return response()->json($this->masters->references(['branches', 'warehouses', 'categories', 'sub_categories', 'brands']));
    }

    public function show(Request $request, int $product)
    {
        abort_unless(AppController::canOpen('stock-summary'), 403);

        return response()->json($this->stock->productInventoryDetail($product, $request->only([
            'branch_id',
            'warehouse_id',
            'product_variant_id',
            'batch_id',
        ])));
    }
}
