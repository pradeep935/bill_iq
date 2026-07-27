<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationTransferRequest;
use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\StockAdjustmentReasonRequest;
use App\Http\Requests\StockAdjustmentVoucherRequest;
use App\Http\Requests\StockCountSessionRequest;
use App\Http\Requests\StockTransferReceiveRequest;
use App\Http\Requests\StockTransferVoucherRequest;
use App\Services\BatchManagementService;
use App\Services\InventoryControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        return Inertia::render('Inventory/BatchExpiry', ['page' => 'inventory-batches', 'title' => 'Batch & Expiry', 'role_id' => AppController::roleId()]);
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

    public function warehouseLocations(Request $request)
    {
        $paginator = $this->inventory->warehouseLocations($request->all());
        return response()->json(['locations' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveWarehouseLocation(Request $request, ?int $location = null)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'zone' => ['nullable', 'string', 'max:100'],
            'aisle' => ['nullable', 'string', 'max:100'],
            'rack' => ['required', 'string', 'max:100'],
            'shelf' => ['required', 'string', 'max:100'],
            'bin' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:active,inactive,blocked'],
        ]);

        return response()->json(['message' => 'Warehouse location saved.', 'location' => $this->inventory->saveWarehouseLocation($data, $location)], $location ? 200 : 201);
    }

    public function batchReferences(BatchManagementService $batches)
    {
        $this->authorizeBatchPermission('batch.view');
        return response()->json($batches->references());
    }

    public function batchList(Request $request, BatchManagementService $batches)
    {
        $this->authorizeBatchPermission('batch.view');
        $paginator = $batches->list($request->all());

        return response()->json([
            'items' => $paginator->getCollection()->values(),
            'dashboard' => $batches->dashboard($request->all()),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function batchShow(Request $request, BatchManagementService $batches, int $batch)
    {
        $this->authorizeBatchPermission('batch.view');
        return response()->json($batches->detail($batch, $request->all()));
    }

    public function batchLedger(Request $request, BatchManagementService $batches, int $batch)
    {
        $this->authorizeBatchPermission('batch.view_ledger');
        return response()->json(['ledger' => $batches->ledger($batch, $request->all())]);
    }

    public function batchFefo(Request $request, BatchManagementService $batches)
    {
        return response()->json(['batch' => $batches->fefo($request->all())]);
    }

    public function batchStatus(Request $request, BatchManagementService $batches, int $batch)
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,blocked,quarantined'],
            'reason' => ['nullable', 'string', 'max:500'],
            'release_outcome' => ['nullable', 'in:saleable,damaged,expired,blocked,return_to_supplier'],
        ]);
        $this->authorizeBatchPermission($data['status'] === 'blocked' ? 'batch.block' : ($data['status'] === 'quarantined' ? 'batch.quarantine' : 'batch.unblock'));

        return response()->json(['message' => 'Batch status updated.', 'batch' => $batches->updateStatus($batch, $data['status'], $data['reason'] ?? null, $data['release_outcome'] ?? null)]);
    }

    public function batchTransfer(Request $request, BatchManagementService $batches, int $batch)
    {
        $this->authorizeBatchPermission('batch.transfer');
        $data = $request->validate([
            'source_branch_id' => ['required', 'integer'],
            'source_warehouse_id' => ['required', 'integer'],
            'destination_branch_id' => ['required', 'integer'],
            'destination_warehouse_id' => ['required', 'integer'],
            'source_location' => ['nullable', 'string', 'max:120'],
            'destination_location' => ['nullable', 'string', 'max:120'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'allow_restricted' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json(['message' => 'Batch transfer posted.', 'batch' => $batches->transfer($batch, $data)]);
    }

    public function batchSplit(Request $request, BatchManagementService $batches, int $batch)
    {
        $this->authorizeBatchPermission('batch.split');
        $data = $request->validate(['batch_number' => ['required', 'string', 'max:100'], 'quantity' => ['required', 'numeric', 'min:0.001']]);

        return response()->json(['message' => 'Batch split posted.', 'batch' => $batches->split($batch, $data)], 201);
    }

    public function batchMerge(Request $request, BatchManagementService $batches, int $batch)
    {
        $this->authorizeBatchPermission('batch.merge');
        $data = $request->validate(['target_batch_id' => ['required', 'integer']]);

        return response()->json(['message' => 'Batch merge posted.', 'batch' => $batches->merge($batch, (int) $data['target_batch_id'])]);
    }

    public function batchReports(Request $request, BatchManagementService $batches)
    {
        $this->authorizeBatchPermission('batch.export');
        return response()->json($batches->reports($request->all()));
    }

    private function authorizeBatchPermission(string $permission): void
    {
        if ((int) AppController::roleId() === 1) {
            return;
        }

        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) {
            abort(403, 'Batch permission is not configured.');
        }

        $allowed = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', AppController::roleId())
            ->where('permissions.name', $permission)
            ->exists();

        abort_unless($allowed, 403, 'You do not have permission for this batch action.');
    }

    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()];
    }
}
