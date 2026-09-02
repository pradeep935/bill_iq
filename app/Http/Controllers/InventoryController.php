<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationTransferRequest;
use App\Http\Requests\BarcodeRequest;
use App\Http\Requests\BomRequest;
use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\ProductionCompleteRequest;
use App\Http\Requests\ProductionOrderRequest;
use App\Http\Requests\SerialBulkRequest;
use App\Http\Requests\SerialNumberRequest;
use App\Http\Requests\StockAdjustmentReasonRequest;
use App\Http\Requests\StockAdjustmentVoucherRequest;
use App\Http\Requests\StockCountSessionRequest;
use App\Http\Requests\StockTransferReceiveRequest;
use App\Http\Requests\StockTransferVoucherRequest;
use App\Services\BatchManagementService;
use App\Services\BarcodeCenterService;
use App\Services\ManufacturingService;
use App\Services\InventoryControlService;
use App\Services\SerialNumberService;
use App\Services\StockService;
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
        if ($redirect = AppController::guardPage('opening-stock')) return $redirect;
        return Inertia::render('Inventory/OpeningStock', ['page' => 'opening-stock', 'title' => 'Opening Stock', 'role_id' => AppController::roleId()]);
    }

    public function currentStock()
    {
        if ($redirect = AppController::guardPage('current-stock')) return $redirect;
        return Inertia::render('Inventory/StockSummary', ['page' => 'inventory-current-stock', 'title' => 'Current Stock', 'role_id' => AppController::roleId()]);
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
        if ($redirect = AppController::guardPage('inventory-serials')) return $redirect;
        return Inertia::render('Inventory/SerialNumbers', ['page' => 'inventory-serials', 'title' => 'Serial Numbers', 'role_id' => AppController::roleId()]);
    }

    public function barcodeCenter()
    {
        if ($redirect = AppController::guardPage('inventory-barcode-center')) return $redirect;
        return Inertia::render('Inventory/BarcodeCenter', ['page' => 'inventory-barcode-center', 'title' => 'Barcode Center', 'role_id' => AppController::roleId()]);
    }

    public function manufacturing()
    {
        if ($redirect = AppController::guardPage('inventory-manufacturing')) return $redirect;
        return Inertia::render('Inventory/Manufacturing', ['page' => 'inventory-manufacturing', 'title' => 'Manufacturing / BOM', 'role_id' => AppController::roleId()]);
    }

    public function references() { return response()->json($this->inventory->references()); }
    public function products(Request $request) { return response()->json($this->inventory->searchProducts((string) $request->get('q', ''))); }
    public function dashboardData(Request $request) { return response()->json($this->inventory->dashboard($request->all())); }
    public function inventoryReports(Request $request) { return response()->json($this->inventory->reports($request->all())); }
    public function movementHistory(Request $request)
    {
        $paginator = $this->inventory->movementHistory($request->all());
        return response()->json(['movements' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function exportMovementHistory(Request $request)
    {
        $format = $request->get('format') === 'excel' ? 'xls' : 'csv';
        $rows = $this->inventory->movementHistoryExport($request->all());
        $columns = [
            'date_time' => 'Date & Time',
            'document_date' => 'Document Date',
            'reference_number' => 'Voucher / Reference No.',
            'source_module' => 'Source Module',
            'movement_type' => 'Movement Type',
            'product' => 'Product',
            'sku' => 'SKU',
            'barcode' => 'Barcode',
            'branch' => 'Branch',
            'warehouse' => 'Warehouse',
            'from_location' => 'From Location',
            'to_location' => 'To Location',
            'from_condition' => 'From Condition',
            'to_condition' => 'To Condition',
            'movement_qty' => 'Movement Qty',
            'qty_in' => 'Qty In',
            'qty_out' => 'Qty Out',
            'net_quantity' => 'Net Quantity',
            'physical_impact' => 'Physical Impact',
            'rate' => 'Rate',
            'movement_value' => 'Movement Value',
            'reason' => 'Reason',
            'remarks' => 'Remarks',
            'user' => 'User',
            'posting_status' => 'Posting Status',
            'impact_summary' => 'Impact Summary',
        ];

        return response()->streamDownload(function () use ($rows, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_values($columns));
            foreach ($rows as $row) {
                fputcsv($handle, collect(array_keys($columns))->map(fn ($key) => $row[$key] ?? '')->all());
            }
            fclose($handle);
        }, 'movement-history.' . $format, ['Content-Type' => $format === 'xls' ? 'application/vnd.ms-excel' : 'text/csv']);
    }
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

    public function seedDefaultReasons()
    {
        $count = $this->inventory->seedDefaultReasons();
        return response()->json(['message' => "{$count} default adjustment reasons created."]);
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
        $data = $request->validated();
        $sessionId = $session ?: ($data['id'] ?? null);

        return response()->json(['message' => 'Stock count session saved.', 'session' => $this->inventory->saveCountSession($data, $sessionId)], $sessionId ? 200 : 201);
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

    public function serialReferences(SerialNumberService $serials)
    {
        $this->authorizeInventoryPermission('serial.view');
        return response()->json($serials->references());
    }

    public function serialList(Request $request, SerialNumberService $serials)
    {
        $this->authorizeInventoryPermission('serial.view');
        $paginator = $serials->list($request->all());
        return response()->json(['items' => $paginator->getCollection()->values(), 'dashboard' => $serials->dashboard($request->all()), 'pagination' => $this->pagination($paginator)]);
    }

    public function serialShow(SerialNumberService $serials, int $serial)
    {
        $this->authorizeInventoryPermission('serial.view');
        return response()->json($serials->detail($serial));
    }

    public function serialStore(SerialNumberRequest $request, SerialNumberService $serials)
    {
        $this->authorizeInventoryPermission('serial.create');
        return response()->json(['message' => 'Serial number saved.', 'serial' => $serials->store($request->validated())], 201);
    }

    public function serialBulk(SerialBulkRequest $request, SerialNumberService $serials)
    {
        $this->authorizeInventoryPermission('serial.create');
        return response()->json(['message' => 'Serial numbers imported.', 'serials' => $serials->bulkStore($request->validated())], 201);
    }

    public function serialUpdate(SerialNumberRequest $request, SerialNumberService $serials, int $serial)
    {
        $this->authorizeInventoryPermission('serial.update');
        return response()->json(['message' => 'Serial metadata updated.', 'serial' => $serials->update($serial, $request->validated())]);
    }

    public function serialStatus(Request $request, SerialNumberService $serials, int $serial)
    {
        $this->authorizeInventoryPermission('serial.status');
        $data = $request->validate(['current_status' => ['required', 'in:in_stock,reserved,sold,returned,damaged,under_repair,lost,transferred,blocked'], 'remarks' => ['nullable', 'string', 'max:1000']]);
        return response()->json(['message' => 'Serial status updated.', 'serial' => $serials->transition($serial, $data['current_status'], $data['remarks'] ?? null)]);
    }

    public function serialTransfer(Request $request, SerialNumberService $serials, StockService $stock, int $serial)
    {
        $this->authorizeInventoryPermission('serial.transfer');
        $data = $request->validate(['destination_branch_id' => ['nullable', 'integer'], 'destination_warehouse_id' => ['required', 'integer'], 'remarks' => ['nullable', 'string', 'max:1000']]);
        return response()->json(['message' => 'Serial transferred.', 'serial' => $serials->transfer($serial, $data, $stock)]);
    }

    public function serialDestroy(SerialNumberService $serials, int $serial)
    {
        $this->authorizeInventoryPermission('serial.delete');
        $serials->destroy($serial);
        return response()->json(['message' => 'Serial deleted.']);
    }

    public function serialReports(Request $request, SerialNumberService $serials)
    {
        $this->authorizeInventoryPermission('serial.export');
        return response()->json($serials->reports($request->all()));
    }

    public function serialSample()
    {
        $this->authorizeInventoryPermission('serial.create');
        return response("product_id,branch_id,warehouse_id,batch_id,serial_number,imei_1,imei_2,condition,purchase_reference,purchase_date,warranty_expiry_date\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename=serial-import-sample.csv']);
    }

    public function barcodeReferences(BarcodeCenterService $barcodes)
    {
        $this->authorizeInventoryPermission('barcode.view');
        return response()->json($barcodes->references());
    }

    public function barcodeList(Request $request, BarcodeCenterService $barcodes)
    {
        $this->authorizeInventoryPermission('barcode.view');
        $paginator = $barcodes->list($request->all());
        return response()->json(['items' => $paginator->getCollection()->values(), 'dashboard' => $barcodes->dashboard($request->all()), 'pagination' => $this->pagination($paginator)]);
    }

    public function barcodeAssign(BarcodeRequest $request, BarcodeCenterService $barcodes)
    {
        $this->authorizeInventoryPermission('barcode.update');
        return response()->json(['message' => 'Barcode saved.', 'barcode' => $barcodes->assign($request->validated())], 201);
    }

    public function barcodeGenerate(Request $request, BarcodeCenterService $barcodes)
    {
        $this->authorizeInventoryPermission('barcode.generate');
        $data = $request->validate(['product_id' => ['required', 'integer'], 'format' => ['nullable', 'in:CODE128,EAN-13,EAN-8,UPC-A,QR'], 'overwrite' => ['nullable', 'boolean']]);
        return response()->json(['message' => 'Barcode generated.', 'barcode' => $barcodes->generate($data)], 201);
    }

    public function barcodeBulkGenerate(Request $request, BarcodeCenterService $barcodes)
    {
        $this->authorizeInventoryPermission('barcode.generate');
        $data = $request->validate(['product_ids' => ['required', 'array', 'min:1'], 'product_ids.*' => ['integer'], 'format' => ['nullable', 'in:CODE128,EAN-13,EAN-8,UPC-A,QR'], 'overwrite' => ['nullable', 'boolean']]);
        return response()->json(['message' => 'Barcodes generated.', 'barcodes' => $barcodes->bulkGenerate($data)], 201);
    }

    public function barcodePrimary(BarcodeCenterService $barcodes, int $barcode)
    {
        $this->authorizeInventoryPermission('barcode.update');
        return response()->json(['message' => 'Primary barcode updated.', 'barcode' => $barcodes->setPrimary($barcode)]);
    }

    public function barcodeToggle(Request $request, BarcodeCenterService $barcodes, int $barcode)
    {
        $this->authorizeInventoryPermission('barcode.update');
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        return response()->json(['message' => 'Barcode status updated.', 'barcode' => $barcodes->toggle($barcode, (bool) $data['is_active'])]);
    }

    public function barcodeScan(Request $request, BarcodeCenterService $barcodes)
    {
        $this->authorizeInventoryPermission('barcode.view');
        $data = $request->validate(['barcode' => ['required', 'string', 'max:120']]);
        return response()->json(['result' => $barcodes->scan($data['barcode'])]);
    }

    public function barcodePrint(Request $request, BarcodeCenterService $barcodes)
    {
        $this->authorizeInventoryPermission('barcode.print');
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'product_barcode_id' => ['nullable', 'integer'],
            'barcode' => ['nullable', 'string', 'max:120'],
            'labels_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'template' => ['nullable', 'string', 'max:80'],
            'paper_size' => ['nullable', 'in:A4,thermal'],
            'width' => ['nullable', 'numeric', 'min:10', 'max:210'],
            'height' => ['nullable', 'numeric', 'min:10', 'max:297'],
            'columns' => ['nullable', 'integer', 'min:1', 'max:10'],
            'margin' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'gap_x' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'gap_y' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'show_name' => ['nullable', 'boolean'],
            'show_sku' => ['nullable', 'boolean'],
            'show_price' => ['nullable', 'boolean'],
            'show_mrp' => ['nullable', 'boolean'],
            'show_business' => ['nullable', 'boolean'],
        ]);
        return response()->json(['message' => 'Label print recorded.', 'print' => $barcodes->print($data)]);
    }

    public function barcodeReports(Request $request, BarcodeCenterService $barcodes)
    {
        $this->authorizeInventoryPermission('barcode.export');
        return response()->json($barcodes->reports($request->all()));
    }

    public function barcodeSample()
    {
        $this->authorizeInventoryPermission('barcode.import');
        return response("product_id,product_variant_id,barcode,format,barcode_type,is_primary,is_active\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename=barcode-import-sample.csv']);
    }

    public function manufacturingReferences(ManufacturingService $manufacturing)
    {
        $this->authorizeInventoryPermission('manufacturing.view');
        return response()->json($manufacturing->references());
    }

    public function manufacturingDashboard(Request $request, ManufacturingService $manufacturing)
    {
        $this->authorizeInventoryPermission('manufacturing.view');
        return response()->json($manufacturing->dashboard($request->all()));
    }

    public function bomList(Request $request, ManufacturingService $manufacturing)
    {
        $this->authorizeInventoryPermission('manufacturing.view');
        $paginator = $manufacturing->boms($request->all());
        return response()->json(['items' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function bomStore(BomRequest $request, ManufacturingService $manufacturing)
    {
        $this->authorizeInventoryPermission('manufacturing.create_bom');
        return response()->json(['message' => 'BOM saved.', 'bom' => $manufacturing->saveBom($request->validated())], 201);
    }

    public function bomUpdate(BomRequest $request, ManufacturingService $manufacturing, int $bom)
    {
        $this->authorizeInventoryPermission('manufacturing.update_bom');
        return response()->json(['message' => 'BOM updated.', 'bom' => $manufacturing->saveBom($request->validated(), $bom)]);
    }

    public function bomDuplicate(ManufacturingService $manufacturing, int $bom)
    {
        $this->authorizeInventoryPermission('manufacturing.create_bom');
        return response()->json(['message' => 'New BOM version created.', 'bom' => $manufacturing->duplicateBom($bom)], 201);
    }

    public function bomActivate(Request $request, ManufacturingService $manufacturing, int $bom)
    {
        $this->authorizeInventoryPermission('manufacturing.approve_bom');
        $data = $request->validate(['active' => ['required', 'boolean']]);
        return response()->json(['message' => 'BOM status updated.', 'bom' => $manufacturing->activateBom($bom, (bool) $data['active'])]);
    }

    public function productionOrderList(Request $request, ManufacturingService $manufacturing)
    {
        $this->authorizeInventoryPermission('manufacturing.view');
        $paginator = $manufacturing->orders($request->all());
        return response()->json(['items' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function productionOrderStore(ProductionOrderRequest $request, ManufacturingService $manufacturing)
    {
        $this->authorizeInventoryPermission('manufacturing.create_order');
        return response()->json(['message' => 'Production order saved.', 'order' => $manufacturing->saveOrder($request->validated())], 201);
    }

    public function productionOrderUpdate(ProductionOrderRequest $request, ManufacturingService $manufacturing, int $order)
    {
        $this->authorizeInventoryPermission('manufacturing.update_order');
        return response()->json(['message' => 'Production order updated.', 'order' => $manufacturing->saveOrder($request->validated(), $order)]);
    }

    public function productionCheck(ManufacturingService $manufacturing, int $order)
    {
        $this->authorizeInventoryPermission('manufacturing.view');
        return response()->json($manufacturing->checkMaterials($order));
    }

    public function productionTransition(Request $request, ManufacturingService $manufacturing, int $order)
    {
        $data = $request->validate(['status' => ['required', 'in:planned,material_reserved,in_progress,cancelled']]);
        $permission = 'manufacturing.update_order';
        if ($data['status'] === 'material_reserved') {
            $permission = 'manufacturing.reserve_materials';
        } elseif ($data['status'] === 'in_progress') {
            $permission = 'manufacturing.start_order';
        } elseif ($data['status'] === 'cancelled') {
            $permission = 'manufacturing.cancel_order';
        }
        $this->authorizeInventoryPermission($permission);
        return response()->json(['message' => 'Production order status updated.', 'order' => $manufacturing->transitionOrder($order, $data['status'])]);
    }

    public function productionComplete(ProductionCompleteRequest $request, ManufacturingService $manufacturing, int $order)
    {
        $this->authorizeInventoryPermission('manufacturing.post_order');
        return response()->json(['message' => 'Production completed and posted.', 'order' => $manufacturing->completeOrder($order, $request->validated())]);
    }

    public function manufacturingReports(Request $request, ManufacturingService $manufacturing)
    {
        $this->authorizeInventoryPermission('manufacturing.export');
        return response()->json($manufacturing->reports($request->all()));
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

    private function authorizeInventoryPermission(string $permission): void
    {
        if ((int) AppController::roleId() === 1) {
            return;
        }

        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) {
            abort(403, 'Inventory permission is not configured.');
        }

        $allowed = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', AppController::roleId())
            ->where('permissions.name', $permission)
            ->exists();

        abort_unless($allowed, 403, 'You do not have permission for this inventory action.');
    }

    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()];
    }
}
