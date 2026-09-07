<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLedger;
use App\Services\BatchIdentityService;
use App\Services\BatchManagementService;
use App\Services\StockService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Reuses the existing condition/transfer test schema, without touching the local business database. */
class BatchExpiryWorkflowTest extends InventoryConditionAdjustmentTest
{
    private array $scope;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 6)->startOfDay());
        Schema::dropIfExists('batch_histories');
        Schema::dropIfExists('users');
        Schema::table('product_batches', function (Blueprint $t) {
            $t->timestamp('posted_at')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->string('batch_number')->nullable();
            $t->date('manufacturing_date')->nullable();
            $t->string('status')->default('active');
            $t->string('condition_status')->default('saleable');
            $t->text('blocked_reason')->nullable();
            $t->timestamp('blocked_at')->nullable();
            $t->timestamp('unblocked_at')->nullable();
            $t->unsignedBigInteger('blocked_by')->nullable();
            $t->unsignedBigInteger('unblocked_by')->nullable();
            $t->timestamp('quarantined_at')->nullable();
        });
        (require database_path('migrations/2026_07_21_000035_complete_batch_audit_history_permissions.php'))->up();
        Schema::table('batch_histories', function (Blueprint $t) {
            $t->uuid('operation_token')->nullable();
            $t->string('request_hash', 64)->nullable();
            $t->unique(['business_id', 'operation_token']);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('role_id')->default(1);
            $t->timestamps();
        });
        $business = DB::table('companies')->insertGetId(['name' => 'Batch Test']);
        $branch = DB::table('branches')->insertGetId(['business_id' => $business, 'name' => 'Default Branch']);
        $warehouse = DB::table('warehouses')->insertGetId(['business_id' => $business, 'branch_id' => $branch, 'name' => 'Default warehouse']);
        session(['business_id' => $business]);
        $this->product = Product::query()->create(['business_id' => $business, 'company_id' => $business, 'name' => 'Mustard Oil 1L', 'sku' => 'MO-1L', 'product_type' => 'goods', 'item_type' => 'stock', 'batch_required' => true, 'tracking_type' => 'batch', 'status' => 'active']);
        $this->scope = ['business_id' => $business, 'product_id' => $this->product->id, 'branch_id' => $branch, 'warehouse_id' => $warehouse];
    }

    private function receipt(string $number = 'MO-BATCH-001', float $qty = 10, float $cost = 100, string $expiry = '2026-12-31'): ProductBatch
    {
        $batch = app(BatchIdentityService::class)->resolve($this->scope['business_id'], $this->product->id, ['batch_number' => $number, 'manufacturing_date' => '2026-08-01', 'expiry_date' => $expiry]);
        app(StockService::class)->increaseStock($this->scope + ['batch_id' => $batch->id, 'quantity' => $qty, 'unit_cost' => $cost, 'reference_type' => ProductBatch::class, 'reference_id' => $batch->id, 'transaction_type' => 'opening_stock']);

        return $batch;
    }

    private function operation(ProductBatch $batch, string $operation, float $quantity = 1, array $extra = []): array
    {
        return app(BatchManagementService::class)->operation(array_merge($this->scope, ['batch_id' => $batch->id, 'operation' => $operation, 'quantity' => $quantity, 'condition_status' => 'saleable', 'reason' => 'Acceptance workflow', 'unit_cost' => 100], $extra));
    }

    public function test_batch_fefo_balances_value_and_repeat_receipt(): void
    {
        $a = $this->receipt();
        $b = $this->receipt('MO-BATCH-002', 5, 105, '2026-10-31');
        $service = app(BatchManagementService::class);
        $this->assertSame($b->id, $service->fefo($this->scope)['id']);
        $this->assertSame(15.0, app(StockService::class)->getPhysicalStock($this->scope));
        $this->assertEquals(1525, $service->dashboard()['total_batch_value']);
        $this->assertCount(2, $service->list()->items());
        $this->assertSame($a->id, $this->receipt(qty: 2, cost: 110)->id);
        $this->assertSame(2, ProductBatch::count());
        $this->assertEquals(101.67, app(StockService::class)->getAverageCost($this->scope + ['batch_id' => $a->id]));
        $this->assertSame(3, StockLedger::count());
    }

    public function test_batch_quarantine_release_damage_preserve_physical_stock(): void
    {
        $batch = $this->receipt();
        $this->operation($batch, 'quarantine', 3);
        $stock = app(StockService::class);
        $this->assertSame(10.0, $stock->getPhysicalStock($this->scope));
        $this->assertSame(7.0, $stock->getAvailableToSell($this->scope));
        $this->assertSame(3.0, $stock->getConditionStock($this->scope, 'quarantined'));
        $this->operation($batch, 'release_quarantine', 2, ['condition_status' => 'quarantined', 'to_condition' => 'saleable']);
        $this->operation($batch, 'reclassify', 2, ['to_condition' => 'damaged']);
        $this->assertSame(10.0, $stock->getPhysicalStock($this->scope));
        $this->assertSame(7.0, $stock->getAvailableToSell($this->scope));
        $this->assertEquals(1000, app(BatchManagementService::class)->dashboard()['total_batch_value']);
        $this->assertSame(7, StockLedger::count());
        $this->assertEquals(10, collect(app(BatchManagementService::class)->list()->items())->sum('quantity_on_hand'));
    }

    public function test_batch_block_and_expiry_prevent_sale_at_posting(): void
    {
        $batch = $this->receipt();
        $service = app(BatchManagementService::class);
        $service->updateStatus($batch->id, 'blocked', 'Inspection');
        $this->assertSame(0.0, app(StockService::class)->getAvailableToSell($this->scope));
        $this->assertNull($service->fefo($this->scope));
        $before = StockLedger::count();
        try {
            app(StockService::class)->decreaseStock($this->scope + ['batch_id' => $batch->id, 'quantity' => 1, 'transaction_type' => 'sale', 'reference_type' => ProductBatch::class, 'reference_id' => $batch->id]);
            $this->fail('Blocked batch was sold');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_id', $e->errors());
        }
        $this->assertSame($before, StockLedger::count());
        $service->updateStatus($batch->id, 'active', 'Inspection passed');
        $this->assertSame(10.0, app(StockService::class)->getAvailableToSell($this->scope));
        $batch->update(['expiry_date' => '2026-09-05']);
        $this->assertSame(0.0, app(StockService::class)->getAvailableToSell($this->scope));
        $this->assertNull($service->fefo($this->scope));
    }

    public function test_batch_transfer_preserves_identity_and_rolls_back_on_invalid_destination(): void
    {
        $batch = $this->receipt();
        $business = $this->scope['business_id'];
        $branch = DB::table('branches')->insertGetId(['business_id' => $business, 'name' => 'Branch Two']);
        $warehouse = DB::table('warehouses')->insertGetId(['business_id' => $business, 'branch_id' => $branch, 'name' => 'Warehouse Two']);
        $this->operation($batch, 'transfer', 3, ['destination_branch_id' => $branch, 'destination_warehouse_id' => $warehouse]);
        $this->assertSame(7.0, app(StockService::class)->getPhysicalStock($this->scope));
        $this->assertSame(3.0, app(StockService::class)->getPhysicalStock(array_merge($this->scope, ['branch_id' => $branch, 'warehouse_id' => $warehouse])));
        $this->assertSame(1, ProductBatch::count());
        $this->assertSame(3, StockLedger::count());
        $this->assertEquals(1000, app(BatchManagementService::class)->dashboard()['total_batch_value']);
    }

    public function test_batch_overdraw_and_tracking_omission_roll_back(): void
    {
        $batch = $this->receipt();
        $before = StockLedger::count();
        try {
            $this->operation($batch, 'writeoff', 11);
            $this->fail('Overdraw allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }
        $this->assertSame($before, StockLedger::count());
        try {
            app(StockService::class)->increaseStock($this->scope + ['quantity' => 1, 'transaction_type' => 'stock_adjustment_in', 'reference_type' => ProductBatch::class, 'reference_id' => $batch->id]);
            $this->fail('Missing batch allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('batch_id', $e->errors());
        }
        $this->assertSame($before, StockLedger::count());
    }

    public function test_batch_filters_pagination_movement_report_and_exports(): void
    {
        $a = $this->receipt();
        $this->receipt('MO-BATCH-002', 5, 105, '2026-10-31');
        $this->operation($a, 'quarantine', 2);
        $service = app(BatchManagementService::class);
        $this->assertSame(1, $service->list(['batch_status' => 'quarantined'])->total());
        $this->assertSame(3, $service->list(['per_page' => 1])->total());
        $this->assertSame(4, $service->movements()->total());
        $rows = $service->movements()->items();
        $this->assertSame('saleable', $rows[0]['from_condition']);
        $this->assertSame('quarantined', $rows[0]['to_condition']);
        $this->assertCount(4, $service->exportRows(['report' => 'batch_movement'])['rows']);
        $this->assertCount(1, $service->exportRows(['report' => 'quarantine_report'])['rows']);
        $this->assertSame(0, $service->list(['search' => 'does-not-exist'])->total());
        $this->assertCount(2, $service->detail($a->id)['balances']);
    }

    public function test_batch_retry_is_idempotent_and_reserved_stock_cannot_be_quarantined(): void
    {
        $batch = $this->receipt();
        $token = (string) Str::uuid();
        $this->operation($batch, 'quarantine', 2, ['operation_token' => $token]);
        $this->operation($batch, 'quarantine', 2, ['operation_token' => $token]);
        $this->assertSame(3, StockLedger::count());
        DB::table('stock_reservations')->insert($this->scope + ['batch_id' => $batch->id, 'reserved_quantity' => 7, 'status' => 'active']);
        $this->expectException(ValidationException::class);
        try {
            $this->operation($batch,'quarantine',2);
        } finally {
            $this->assertSame(3,StockLedger::count());
        }
    }

    public function test_batch_conflicting_dates_are_rejected_without_changing_history(): void
    {
        $this->receipt();
        $this->expectException(ValidationException::class);
        $this->receipt(expiry: '2027-01-01');
    }
}
