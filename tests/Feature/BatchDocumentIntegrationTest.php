<?php

namespace Tests\Feature;

use App\Models\ProductBatch;
use App\Models\PurchaseReturnVoucher;
use App\Models\PurchaseVoucher;
use App\Models\StockLedger;
use App\Services\BatchManagementService;
use App\Services\InventoryControlService;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SalesReturnService;
use App\Services\SalesService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;

class BatchDocumentIntegrationTest extends SalesInventoryFlowTest
{
    public function test_batch_documents_post_and_reconcile_on_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName(), 'Run using scripts/test-batches-mysql.php');
        $this->travelTo(now()->setDate(2026, 9, 6)->startOfDay());
        [$business,$user,$product,$branch,$warehouse,$customer] = $this->fixture();
        $this->loginBusiness($user, $business);
        $product->update(['name' => 'Mustard Oil 1L', 'sku' => 'MO-1L', 'tracking_type' => 'batch', 'batch_required' => true]);
        $scope = ['business_id' => $business, 'product_id' => $product->id, 'branch_id' => $branch, 'warehouse_id' => $warehouse];
        $batches = app(BatchManagementService::class);
        $stock = app(StockService::class);
        $opening = $batches->operation($scope + ['operation' => 'opening', 'batch_number' => 'MO-BATCH-001', 'manufacturing_date' => '2026-08-01', 'expiry_date' => '2026-12-31', 'quantity' => 10, 'unit_cost' => 100, 'condition_status' => 'saleable', 'reason' => 'Opening acceptance']);
        $this->assertSame(10.0, $stock->getPhysicalStock($scope));
        $supplier = DB::table('suppliers')->insertGetId(['business_id' => $business, 'name' => 'Batch Supplier', 'supplier_name' => 'Batch Supplier', 'status' => 'active']);
        $purchase = PurchaseVoucher::query()->create(['business_id' => $business, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'supplier_id' => $supplier, 'voucher_number' => 'PUR-BATCH-TEST', 'purchase_date' => '2026-09-06', 'purchase_type' => 'credit', 'tax_type' => 'exempt', 'status' => 'draft', 'subtotal' => 525, 'taxable_amount' => 525, 'grand_total' => 525, 'balance_amount' => 525]);
        $purchase->items()->create(['product_id' => $product->id, 'quantity' => 5, 'purchase_rate' => 105, 'taxable_amount' => 525, 'line_total' => 525, 'batch_number' => 'MO-BATCH-002', 'manufacturing_date' => '2026-08-01', 'expiry_date' => '2026-10-31']);
        app(PurchaseService::class)->post($purchase);
        $batchB = ProductBatch::query()->where('batch_no', 'MO-BATCH-002')->firstOrFail();
        $this->assertSame($batchB->id, $batches->fefo($scope)['id']);
        $this->assertEquals(1525, $batches->dashboard()['total_batch_value']);
        $sale = $this->sale($business, $branch, $warehouse, $customer, $product->id, 3);
        $sale->items()->update(['batch_id' => $batchB->id, 'cost_rate' => 105]);
        app(SalesService::class)->post($sale);
        $this->assertSame(2.0, $stock->getCurrentStock($scope + ['batch_id' => $batchB->id]));
        $returnPayload = $this->returnPayload($sale->fresh(['items.product']), 1);
        $returnPayload['items'][0]['condition_status'] = 'good';
        $returnPayload['items'][0]['restock_status'] = 'restock';
        $return = app(SalesReturnService::class)->create($returnPayload);
        app(SalesReturnService::class)->post($return);
        $this->assertSame(3.0, $stock->getCurrentStock($scope + ['batch_id' => $batchB->id]));
        app(SalesReturnService::class)->post($return->fresh());
        $this->assertSame(3.0, $stock->getCurrentStock($scope + ['batch_id' => $batchB->id]));
        $pr = PurchaseReturnVoucher::query()->create(['business_id' => $business, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'supplier_id' => $supplier, 'purchase_voucher_id' => $purchase->id, 'voucher_number' => 'PR-BATCH-TEST', 'return_date' => '2026-09-06', 'return_type' => 'against_purchase', 'tax_type' => 'exempt', 'status' => 'draft', 'subtotal' => 105, 'taxable_amount' => 105, 'grand_total' => 105]);
        $pr->items()->create(['purchase_item_id' => $purchase->items()->value('id'), 'product_id' => $product->id, 'batch_id' => $batchB->id, 'quantity' => 1, 'purchase_rate' => 105, 'line_total' => 105]);
        app(PurchaseReturnService::class)->post($pr);
        $this->assertSame(2.0, $stock->getCurrentStock($scope + ['batch_id' => $batchB->id]));
        $count = app(InventoryControlService::class)->saveCountSession(['branch_id' => $branch, 'warehouse_id' => $warehouse, 'count_date' => '2026-09-06', 'count_type' => 'full', 'freeze_stock' => false, 'status' => 'approved', 'remarks' => 'Batch physical count', 'items' => [['product_id' => $product->id, 'batch_id' => $batchB->id, 'counted_quantity' => 3, 'review_status' => 'accepted', 'unit_cost' => 105]]]);
        app(InventoryControlService::class)->postCountVariance($count->id);
        $this->assertSame(3.0, $stock->getCurrentStock($scope + ['batch_id' => $batchB->id]));
        $this->assertEquals($stock->getPhysicalStock($scope), collect($batches->list($scope)->items())->sum('quantity_on_hand'));
        $this->assertEquals($stock->getAvailableToSell($scope), collect($batches->list($scope)->items())->sum('quantity_available'));
        $this->assertEquals(1315, $batches->dashboard()['total_batch_value']);
        $this->assertSame(StockLedger::query()->where('product_id', $product->id)->count(), $batches->movements(['product_id' => $product->id])->total());
        foreach (['csv', 'excel', 'pdf'] as $format) {
            $response = $this->get('/app/inventory/batches/export?format='.$format);
            $response->assertOk();
            $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
            if ($format !== 'pdf') {
                $this->assertNotEmpty($response->streamedContent());
            } else {
                $this->assertStringStartsWith('%PDF', $response->getContent());
            }
        }
        $this->getJson('/app/inventory/batches/list?per_page=1')->assertOk()->assertJsonPath('pagination.total',2);
        $this->getJson('/app/inventory/batches/movements')->assertOk();
    }
}
