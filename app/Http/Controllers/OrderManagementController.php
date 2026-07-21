<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeliveryChallanRequest;
use App\Http\Requests\GoodsReceiptRequest;
use App\Http\Requests\PurchaseOrderRequest;
use App\Http\Requests\PurchaseRequisitionRequest;
use App\Http\Requests\QuotationRequest;
use App\Http\Requests\SalesOrderRequest;
use App\Services\OrderManagementService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderManagementController extends Controller
{
    private OrderManagementService $orders;

    public function __construct(OrderManagementService $orders)
    {
        $this->orders = $orders;
    }

    public function salesPage()
    {
        if ($redirect = AppController::guardPage('sales')) {
            return $redirect;
        }

        return Inertia::render('Orders/Management', [
            'page' => 'sales',
            'title' => 'Sales Orders',
            'initial_tab' => 'sales-orders',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function purchasePage()
    {
        if ($redirect = AppController::guardPage('inventory-orders')) {
            return $redirect;
        }

        return Inertia::render('Orders/Management', [
            'page' => 'inventory-orders',
            'title' => 'Purchase Orders',
            'initial_tab' => 'purchase-orders',
            'role_id' => AppController::roleId(),
        ]);
    }

    public function references()
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->orders->references());
    }

    public function searchProducts(Request $request)
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->orders->searchProducts(trim((string) $request->query('q'))));
    }

    public function dashboard()
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->orders->dashboard());
    }

    public function reports()
    {
        abort_unless(AppController::canOpen('sales') || AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->orders->reports());
    }

    public function quotations(Request $request)
    {
        abort_unless(AppController::canOpen('sales'), 403);

        return response()->json($this->page('quotations', $this->orders->quotations($request->all())));
    }

    public function saveQuotation(QuotationRequest $request, ?int $quotation = null)
    {
        abort_unless(AppController::canOpen('sales'), 403);
        $row = $this->orders->saveQuotation($request->validated(), $quotation);

        return response()->json(['message' => 'Quotation saved successfully.', 'quotation' => $row], $quotation ? 200 : 201);
    }

    public function convertQuotation(int $quotation)
    {
        abort_unless(AppController::canOpen('sales'), 403);
        $order = $this->orders->convertQuotation($quotation);

        return response()->json(['message' => 'Quotation converted to sales order.', 'sales_order' => $order], 201);
    }

    public function salesOrders(Request $request)
    {
        abort_unless(AppController::canOpen('sales'), 403);

        return response()->json($this->page('sales_orders', $this->orders->salesOrders($request->all())));
    }

    public function saveSalesOrder(SalesOrderRequest $request, ?int $order = null)
    {
        abort_unless(AppController::canOpen('sales'), 403);
        $row = $this->orders->saveSalesOrder($request->validated(), $order);

        return response()->json(['message' => 'Sales order saved successfully.', 'sales_order' => $row], $order ? 200 : 201);
    }

    public function approveSalesOrder(int $order)
    {
        abort_unless(AppController::canOpen('sales'), 403);
        $row = $this->orders->approveSalesOrder($order);

        return response()->json(['message' => 'Sales order approved and stock reserved.', 'sales_order' => $row]);
    }

    public function deliveryChallans(Request $request)
    {
        abort_unless(AppController::canOpen('sales'), 403);

        return response()->json($this->page('delivery_challans', $this->orders->deliveryChallans($request->all())));
    }

    public function saveDeliveryChallan(DeliveryChallanRequest $request, ?int $challan = null)
    {
        abort_unless(AppController::canOpen('sales'), 403);
        $row = $this->orders->saveDeliveryChallan($request->validated(), $challan);

        return response()->json(['message' => 'Delivery challan saved successfully.', 'delivery_challan' => $row], $challan ? 200 : 201);
    }

    public function dispatchChallan(int $challan)
    {
        abort_unless(AppController::canOpen('sales'), 403);
        $row = $this->orders->dispatchChallan($challan);

        return response()->json(['message' => 'Delivery challan dispatched and stock reduced.', 'delivery_challan' => $row]);
    }

    public function requisitions(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->page('purchase_requisitions', $this->orders->requisitions($request->all())));
    }

    public function saveRequisition(PurchaseRequisitionRequest $request, ?int $requisition = null)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);
        $row = $this->orders->saveRequisition($request->validated(), $requisition);

        return response()->json(['message' => 'Purchase requisition saved successfully.', 'purchase_requisition' => $row], $requisition ? 200 : 201);
    }

    public function purchaseOrders(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->page('purchase_orders', $this->orders->purchaseOrders($request->all())));
    }

    public function savePurchaseOrder(PurchaseOrderRequest $request, ?int $order = null)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);
        $row = $this->orders->savePurchaseOrder($request->validated(), $order);

        return response()->json(['message' => 'Purchase order saved successfully.', 'purchase_order' => $row], $order ? 200 : 201);
    }

    public function confirmPurchaseOrder(Request $request, int $order)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);
        $row = $this->orders->confirmPurchaseOrder($order, $request->validate([
            'confirmation_status' => ['nullable', 'in:accepted,rejected,modified'],
            'expected_delivery_date' => ['nullable', 'date'],
            'items' => ['nullable', 'array'],
            'remarks' => ['nullable', 'string'],
        ]));

        return response()->json(['message' => 'Supplier confirmation saved.', 'purchase_order' => $row]);
    }

    public function goodsReceipts(Request $request)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);

        return response()->json($this->page('goods_receipts', $this->orders->goodsReceipts($request->all())));
    }

    public function saveGoodsReceipt(GoodsReceiptRequest $request, ?int $receipt = null)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);
        $row = $this->orders->saveGoodsReceipt($request->validated(), $receipt);

        return response()->json(['message' => 'Goods receipt saved successfully.', 'goods_receipt' => $row], $receipt ? 200 : 201);
    }

    public function receiveGoods(int $receipt)
    {
        abort_unless(AppController::canOpen('inventory-orders'), 403);
        $row = $this->orders->receiveGoods($receipt);

        return response()->json(['message' => 'Goods received and stock increased.', 'goods_receipt' => $row]);
    }

    private function page(string $key, $paginator): array
    {
        return [
            $key => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
