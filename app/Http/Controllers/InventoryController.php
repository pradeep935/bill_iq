<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationTransferRequest;
use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\StockAdjustmentReasonRequest;
use App\Http\Requests\StockAdjustmentVoucherRequest;
use App\Http\Requests\StockCountSessionRequest;
use App\Http\Requests\StockTransferReceiveRequest;
use App\Http\Requests\StockTransferVoucherRequest;
use App\Services\InventoryControlService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryController extends Controller
{
    private InventoryControlService $inventory;

    public function __construct(InventoryControlService $inventory)
    {
        $this->inventory = $inventory;
    }

    public function dashboard()
    {
        if ($redirect = AppController::guardPage('inventory')) return $redirect;
        return Inertia::render('Inventory/Control', ['page' => 'inventory', 'title' => 'Inventory Control', 'initial_tab' => 'dashboard']);
    }

    public function add()
    {
        return ModuleController::render('inventory-add', 'Add Inventory');
    }

    public function currentStock()
    {
        return ModuleController::render('inventory-current-stock', 'Current Stock');
    }

    public function vouchers()
    {
        if ($redirect = AppController::guardPage('inventory-vouchers')) return $redirect;
        return Inertia::render('Inventory/Control', ['page' => 'inventory-vouchers', 'title' => 'Inventory Vouchers', 'initial_tab' => 'adjustments']);
    }

    public function batches()
    {
        return ModuleController::render('inventory-batches', 'Batch & Expiry');
    }

    public function serials()
    {
        return ModuleController::render('inventory-serials', 'Serial Numbers');
    }

    public function barcodeCenter()
    {
        return ModuleController::render('inventory-barcode-center', 'Barcode Center');
    }

    public function manufacturing()
    {
        return ModuleController::render('inventory-manufacturing', 'Manufacturing / BOM');
    }

    public function references() { return response()->json($this->inventory->references()); }
    public function products(Request $request) { return response()->json($this->inventory->searchProducts((string) $request->get('q', ''))); }
    public function dashboardData(Request $request) { return response()->json($this->inventory->dashboard($request->all())); }
    public function inventoryReports(Request $request) { return response()->json($this->inventory->reports($request->all())); }
    public function valuation(Request $request) { return response()->json($this->inventory->valuation($request->all())); }

    public function reasons(Request $request)
    {
        $paginator = $this->inventory->reasons($request->all());
        return response()->json(['reasons' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveReason(StockAdjustmentReasonRequest $request, ?int $reason = null)
    {
        return response()->json(['message' => 'Adjustment reason saved.', 'reason' => $this->inventory->saveReason($request->validated(), $reason)], $reason ? 200 : 201);
    }

    public function deleteReason(Request $request, int $reason)
    {
        $this->inventory->deleteReason($reason, $request->boolean('force'));
        return response()->json(['message' => 'Adjustment reason deleted.']);
    }

    public function adjustments(Request $request)
    {
        $paginator = $this->inventory->adjustments($request->all());
        return response()->json(['adjustments' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveAdjustment(StockAdjustmentVoucherRequest $request, ?int $adjustment = null)
    {
        return response()->json(['message' => 'Stock adjustment saved.', 'adjustment' => $this->inventory->saveAdjustment($request->validated(), $adjustment)], $adjustment ? 200 : 201);
    }

    public function postAdjustment(int $adjustment)
    {
        return response()->json(['message' => 'Stock adjustment posted.', 'adjustment' => $this->inventory->postAdjustment($adjustment)]);
    }

    public function reverseAdjustment(OpeningStockReverseRequest $request, int $adjustment)
    {
        return response()->json(['message' => 'Stock adjustment reversed.', 'adjustment' => $this->inventory->reverseAdjustment($adjustment, $request->validated()['remarks'])]);
    }

    public function countSessions(Request $request)
    {
        $paginator = $this->inventory->countSessions($request->all());
        return response()->json(['sessions' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveCountSession(StockCountSessionRequest $request, ?int $session = null)
    {
        return response()->json(['message' => 'Stock count session saved.', 'session' => $this->inventory->saveCountSession($request->validated(), $session)], $session ? 200 : 201);
    }

    public function scanCountLine(Request $request, int $session)
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'batch_id' => ['nullable', 'integer'], 'warehouse_location' => ['nullable', 'string'], 'quantity' => ['required', 'numeric', 'min:0.001']]);
        return response()->json(['message' => 'Count line saved.', 'session' => $this->inventory->scanCountLine($session, $data)]);
    }

    public function postCountVariance(int $session)
    {
        return response()->json(['message' => 'Count variance posted.', 'adjustment' => $this->inventory->postCountVariance($session)]);
    }

    public function transfers(Request $request)
    {
        $paginator = $this->inventory->transfers($request->all());
        return response()->json(['transfers' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveTransfer(StockTransferVoucherRequest $request, ?int $transfer = null)
    {
        return response()->json(['message' => 'Stock transfer saved.', 'transfer' => $this->inventory->saveTransfer($request->validated(), $transfer)], $transfer ? 200 : 201);
    }

    public function dispatchTransfer(int $transfer)
    {
        return response()->json(['message' => 'Stock transfer dispatched.', 'transfer' => $this->inventory->dispatchTransfer($transfer)]);
    }

    public function receiveTransfer(StockTransferReceiveRequest $request, int $transfer)
    {
        return response()->json(['message' => 'Stock transfer received.', 'transfer' => $this->inventory->receiveTransfer($transfer, $request->validated())]);
    }

    public function locationTransfers(Request $request)
    {
        $paginator = $this->inventory->locationTransfers($request->all());
        return response()->json(['movements' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveLocationTransfer(LocationTransferRequest $request, ?int $movement = null)
    {
        return response()->json(['message' => 'Location movement saved.', 'movement' => $this->inventory->saveLocationTransfer($request->validated(), $movement)], $movement ? 200 : 201);
    }

    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()];
    }
}
