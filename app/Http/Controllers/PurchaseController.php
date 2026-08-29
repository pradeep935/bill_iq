<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\PurchaseVoucherRequest;
use App\Models\PurchaseVoucher;
use App\Services\InventoryOrderService;
use App\Services\PurchaseService;
use App\Services\ReorderSuggestionService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PurchaseController extends Controller
{
    private PurchaseService $purchases;
    private ReorderSuggestionService $reorderSuggestions;
    private InventoryOrderService $inventoryOrders;

    public function __construct(PurchaseService $purchases, ReorderSuggestionService $reorderSuggestions, InventoryOrderService $inventoryOrders)
    {
        $this->purchases = $purchases;
        $this->reorderSuggestions = $reorderSuggestions;
        $this->inventoryOrders = $inventoryOrders;
    }

    public function bills()
    {
        if ($redirect = AppController::guardPage('purchases')) {
            return $redirect;
        }

        return Inertia::render('Purchase/Purchases', [
            'page' => 'purchases',
            'title' => 'Purchases',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function suppliers()
    {
        return app(SupplierController::class)->index();
    }

    public function grn()
    {
        if ($redirect = AppController::guardPage('inventory-orders')) return $redirect;
        return Inertia::render('Purchase/Workflow', ['page' => 'inventory-inward', 'title' => 'Stock Inward / GRN', 'initial_tab' => 'grn']);
    }

    public function reorder()
    {
        if ($redirect = AppController::guardPage('purchases')) return $redirect;
        return Inertia::render('Purchase/ReorderSuggestions', ['page' => 'inventory-reorder', 'title' => 'Reorder Suggestions']);
    }

    public function reorderReferences()
    {
        abort_unless(AppController::canOpen('purchases'), 403);

        return response()->json($this->reorderSuggestions->references());
    }

    public function reorderList(Request $request)
    {
        abort_unless(AppController::canOpen('purchases'), 403);

        $result = $this->reorderSuggestions->list($request->all());
        $paginator = $result['rows'];

        return response()->json([
            'suggestions' => $paginator->items(),
            'summary' => $result['summary'],
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

    public function reorderCreateRequisition(Request $request)
    {
        abort_unless(AppController::canOpen('purchases'), 403);

        $requisition = $this->reorderSuggestions->createRequisition($request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.branch_id' => ['nullable', 'integer'],
            'items.*.warehouse_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.purchase_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.supplier_id' => ['nullable', 'integer'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0'],
        ])['items']);

        return response()->json([
            'message' => "Requisition {$requisition->requisition_number} created successfully.",
            'requisition' => $requisition,
        ], 201);
    }

    public function reorderCreatePurchaseOrders(Request $request)
    {
        abort_unless(AppController::canOpen('purchases'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'in:draft,confirmed'],
            'expected_delivery_date' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.branch_id' => ['nullable', 'integer'],
            'items.*.warehouse_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.purchase_rate' => ['required', 'numeric', 'min:0'],
            'items.*.supplier_id' => ['required', 'integer'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $orders = $this->reorderSuggestions->createPurchaseOrders($data['items'], $data['status'] ?? 'draft', $data['expected_delivery_date'] ?? null);

        return response()->json([
            'message' => $orders->count() === 1 ? "Purchase order {$orders->first()->po_number} created successfully." : "{$orders->count()} supplier-wise purchase orders created successfully.",
            'purchase_orders' => $orders,
        ], 201);
    }

    public function orders()
    {
        if ($redirect = AppController::guardPage('inventory-orders')) return $redirect;
        return Inertia::render('Purchase/InventoryOrders', ['page' => 'inventory-orders', 'title' => 'Inventory Orders']);
    }

    public function inventoryOrderReferences()
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->inventoryOrders->references());
    }

    public function inventoryOrderList(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        $result = $this->inventoryOrders->list($request->all());
        $paginator = $result['orders'];

        return response()->json([
            'orders' => $paginator->items(),
            'summary' => $result['summary'],
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

    public function inventoryOrderProducts(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->inventoryOrders->productSearch(trim((string) $request->query('q'))));
    }

    public function inventoryOrderSave(Request $request, ?int $order = null)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        $row = $this->inventoryOrders->save($request->validate([
            'source_type' => ['required', 'in:manual,reorder_suggestion,requisition,stock_planning'],
            'source_reference' => ['nullable', 'string', 'max:80'],
            'purchase_requisition_id' => ['nullable', 'integer'],
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'priority' => ['required', 'in:normal,urgent,critical'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:draft,pending_approval,approved,ordered,cancelled'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.ordered_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.purchase_rate' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string'],
        ]), $order);

        return response()->json(['message' => 'Inventory order saved successfully.', 'order' => $row], $order ? 200 : 201);
    }

    public function inventoryOrderMarkOrdered(int $order)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        $row = $this->inventoryOrders->markOrdered($order);

        return response()->json(['message' => 'Inventory order marked as ordered.', 'order' => $row]);
    }

    public function inventoryOrderCancel(int $order)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        $row = $this->inventoryOrders->cancel($order);

        return response()->json(['message' => 'Inventory order cancelled.', 'order' => $row]);
    }

    public function list(Request $request)
    {
        abort_unless(AppController::canOpen('purchases'), 403);

        $paginator = $this->purchases->list($request->all());

        return response()->json([
            'purchases' => $paginator->getCollection()->map(fn (PurchaseVoucher $voucher) => $this->purchases->present($voucher))->values(),
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
        abort_unless(AppController::canOpen('purchases'), 403);

        return response()->json($this->purchases->references());
    }

    public function searchProducts(Request $request)
    {
        abort_unless(AppController::canOpen('purchases'), 403);

        return response()->json($this->purchases->searchProducts(trim((string) $request->query('q'))));
    }

    public function store(PurchaseVoucherRequest $request)
    {
        $voucher = $this->purchases->create($request->validated());

        return response()->json([
            'message' => 'Purchase saved successfully.',
            'purchase' => $this->purchases->present($voucher),
        ], 201);
    }

    public function update(PurchaseVoucherRequest $request, int $purchase)
    {
        $voucher = $this->voucher($purchase);
        $voucher = $this->purchases->update($voucher, $request->validated());

        return response()->json([
            'message' => 'Purchase updated successfully.',
            'purchase' => $this->purchases->present($voucher),
        ]);
    }

    public function duplicate(int $purchase)
    {
        $voucher = $this->purchases->duplicate($this->voucher($purchase));

        return response()->json([
            'message' => 'Purchase duplicated successfully.',
            'purchase' => $this->purchases->present($voucher),
        ], 201);
    }

    public function approve(int $purchase)
    {
        $voucher = $this->purchases->post($this->voucher($purchase), 'approved');

        return response()->json([
            'message' => 'Purchase posted successfully.',
            'purchase' => $this->purchases->present($voucher),
        ]);
    }

    public function cancel(int $purchase)
    {
        $voucher = $this->purchases->cancel($this->voucher($purchase));

        return response()->json([
            'message' => 'Purchase cancelled successfully.',
            'purchase' => $this->purchases->present($voucher),
        ]);
    }

    public function reverse(OpeningStockReverseRequest $request, int $purchase)
    {
        $voucher = $this->purchases->reverse($this->voucher($purchase), $request->validated()['remarks']);

        return response()->json([
            'message' => 'Purchase reversed successfully.',
            'purchase' => $this->purchases->present($voucher),
        ]);
    }

    private function voucher(int $id): PurchaseVoucher
    {
        return PurchaseVoucher::query()
            ->where('business_id', AppController::businessId())
            ->with(['supplier', 'branch', 'warehouse', 'creator', 'items.product', 'items.variant', 'items.batch'])
            ->where('id', $id)
            ->firstOrFail();
    }
}
