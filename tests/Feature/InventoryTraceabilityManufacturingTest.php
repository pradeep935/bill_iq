<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductSerialNumber;
use App\Models\ProductionOrder;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryTraceabilityManufacturingTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_serial_is_rejected(): void
    {
        [$businessId, $user, $product, $branch, $warehouse] = $this->fixture();

        ProductSerialNumber::query()->create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'branch_id' => $branch,
            'warehouse_id' => $warehouse,
            'serial_number' => 'SN-001',
            'normalized_serial_number' => 'SN001',
            'current_status' => 'in_stock',
            'status' => 'in_stock',
        ]);

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/serials', [
                'product_id' => $product->id,
                'branch_id' => $branch,
                'warehouse_id' => $warehouse,
                'serial_number' => 'SN 001',
            ])
            ->assertUnprocessable();
    }

    public function test_sold_or_blocked_serial_cannot_be_transferred(): void
    {
        [$businessId, $user, $product, $branch, $warehouse, $otherWarehouse] = $this->fixture();
        $serial = ProductSerialNumber::query()->create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'branch_id' => $branch,
            'warehouse_id' => $warehouse,
            'serial_number' => 'SN-002',
            'normalized_serial_number' => 'SN002',
            'current_status' => 'blocked',
            'status' => 'blocked',
        ]);

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson("/app/inventory/serials/{$serial->id}/transfer", [
                'destination_branch_id' => $branch,
                'destination_warehouse_id' => $otherWarehouse,
            ])
            ->assertUnprocessable();
    }

    public function test_serial_transfer_updates_location_and_ledger(): void
    {
        [$businessId, $user, $product, $branch, $warehouse, $otherWarehouse] = $this->fixture();
        $serial = ProductSerialNumber::query()->create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'branch_id' => $branch,
            'warehouse_id' => $warehouse,
            'serial_number' => 'SN-003',
            'normalized_serial_number' => 'SN003',
            'current_status' => 'in_stock',
            'status' => 'in_stock',
        ]);
        StockLedger::query()->create(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product->id, 'serial_id' => $serial->id, 'transaction_type' => 'opening_stock', 'reference_type' => ProductSerialNumber::class, 'reference_id' => $serial->id, 'quantity_in' => 1, 'quantity_out' => 0, 'unit_cost' => 50, 'stock_value' => 50]);

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson("/app/inventory/serials/{$serial->id}/transfer", [
                'destination_branch_id' => $branch,
                'destination_warehouse_id' => $otherWarehouse,
            ])
            ->assertOk();

        $this->assertDatabaseHas('product_serial_numbers', ['id' => $serial->id, 'warehouse_id' => $otherWarehouse, 'current_status' => 'transferred']);
        $this->assertDatabaseHas('stock_ledgers', ['business_id' => $businessId, 'serial_id' => $serial->id, 'transaction_type' => 'stock_transfer_out']);
        $this->assertDatabaseHas('stock_ledgers', ['business_id' => $businessId, 'serial_id' => $serial->id, 'transaction_type' => 'stock_transfer_in']);
    }

    public function test_duplicate_active_barcode_is_rejected(): void
    {
        [$businessId, $user, $product] = $this->fixture();
        $other = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Other', 'sku' => 'OTH-1', 'tracking_type' => 'none', 'status' => 'active']);
        ProductBarcode::query()->create(['business_id' => $businessId, 'product_id' => $other->id, 'barcode' => 'ABC123', 'is_primary' => true, 'is_active' => true, 'status' => 'active']);

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/barcode-center/assign', ['product_id' => $product->id, 'barcode' => 'ABC123', 'format' => 'CODE128', 'barcode_type' => 'internal', 'is_primary' => true])
            ->assertUnprocessable();
    }

    public function test_barcode_generation_and_lookup_work(): void
    {
        [$businessId, $user, $product] = $this->fixture();

        $barcode = $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/barcode-center/generate', ['product_id' => $product->id, 'format' => 'CODE128'])
            ->assertCreated()
            ->json('barcode.barcode');

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/barcode-center/scan', ['barcode' => $barcode])
            ->assertOk()
            ->assertJsonPath('result.product.id', $product->id);
    }

    public function test_circular_bom_is_rejected(): void
    {
        [$businessId, $user, $product, $branch, $warehouse] = $this->fixture();
        $raw = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Raw Item', 'sku' => 'RAW-1', 'tracking_type' => 'none', 'status' => 'active']);

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/manufacturing/boms', [
                'finished_product_id' => $product->id,
                'bom_name' => 'Bad BOM',
                'output_quantity' => 1,
                'items' => [['raw_material_product_id' => $product->id, 'quantity_required' => 1, 'warehouse_id' => $warehouse]],
            ])
            ->assertUnprocessable();

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/manufacturing/boms', [
                'finished_product_id' => $product->id,
                'bom_name' => 'Good BOM',
                'output_quantity' => 1,
                'items' => [['raw_material_product_id' => $raw->id, 'quantity_required' => 1, 'warehouse_id' => $warehouse]],
            ])
            ->assertCreated();
    }

    public function test_insufficient_material_prevents_production_posting(): void
    {
        [$businessId, $user, $product, $branch, $warehouse] = $this->fixture();
        $raw = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Raw Item', 'sku' => 'RAW-2', 'tracking_type' => 'none', 'status' => 'active']);
        $bomId = $this->createBom($user, $businessId, $product->id, $raw->id, $warehouse);
        $orderId = $this->createOrder($user, $businessId, $bomId, $branch, $warehouse, 2);

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson("/app/inventory/manufacturing/orders/{$orderId}/complete", ['produced_quantity' => 2])
            ->assertUnprocessable();
    }

    public function test_completing_production_creates_stock_out_and_stock_in_entries(): void
    {
        [$businessId, $user, $product, $branch, $warehouse] = $this->fixture();
        $raw = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Raw Item', 'sku' => 'RAW-3', 'tracking_type' => 'none', 'status' => 'active']);
        StockLedger::query()->create(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $raw->id, 'transaction_type' => 'opening_stock', 'reference_type' => Product::class, 'reference_id' => $raw->id, 'quantity_in' => 10, 'quantity_out' => 0, 'unit_cost' => 5, 'stock_value' => 50]);
        $bomId = $this->createBom($user, $businessId, $product->id, $raw->id, $warehouse);
        $orderId = $this->createOrder($user, $businessId, $bomId, $branch, $warehouse, 2);

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson("/app/inventory/manufacturing/orders/{$orderId}/complete", ['produced_quantity' => 2])
            ->assertOk();

        $this->assertDatabaseHas('stock_ledgers', ['business_id' => $businessId, 'reference_id' => $orderId, 'transaction_type' => 'manufacturing_consumption']);
        $this->assertDatabaseHas('stock_ledgers', ['business_id' => $businessId, 'reference_id' => $orderId, 'transaction_type' => 'manufacturing_output']);
        $this->assertDatabaseHas('production_orders', ['id' => $orderId, 'status' => 'completed']);
    }

    public function test_completed_production_order_cannot_be_edited(): void
    {
        [$businessId, $user, $product, $branch, $warehouse] = $this->fixture();
        $order = ProductionOrder::query()->create(['business_id' => $businessId, 'order_number' => 'PO-X', 'bom_id' => 1, 'finished_product_id' => $product->id, 'branch_id' => $branch, 'source_warehouse_id' => $warehouse, 'finished_goods_warehouse_id' => $warehouse, 'planned_quantity' => 1, 'status' => 'completed']);

        $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->putJson("/app/inventory/manufacturing/orders/{$order->id}", ['bom_id' => 1, 'source_warehouse_id' => $warehouse, 'finished_goods_warehouse_id' => $warehouse, 'planned_quantity' => 1])
            ->assertUnprocessable();
    }

    private function fixture(): array
    {
        $businessId = DB::table('companies')->insertGetId(['name' => 'ABC Retail Pvt Ltd', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['role_id' => 1, 'is_active' => 1, 'status' => 'active']);
        $branch = DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branch, 'name' => 'Store', 'code' => 'ST', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $otherWarehouse = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branch, 'name' => 'Backroom', 'code' => 'BK', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Serial Product', 'sku' => 'SER-1', 'tracking_type' => 'serial', 'serial_required' => true, 'batch_required' => false, 'status' => 'active']);

        return [$businessId, $user, $product, $branch, $warehouse, $otherWarehouse];
    }

    private function createBom(User $user, int $businessId, int $finishedProductId, int $rawProductId, int $warehouseId): int
    {
        $response = $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/manufacturing/boms', [
                'finished_product_id' => $finishedProductId,
                'bom_name' => 'Test BOM',
                'output_quantity' => 1,
                'items' => [['raw_material_product_id' => $rawProductId, 'quantity_required' => 1, 'warehouse_id' => $warehouseId]],
            ]);
        $id = $response->assertCreated()->json('bom.id');
        $this->actingAs($user)->withSession(['business_id' => $businessId])->postJson("/app/inventory/manufacturing/boms/{$id}/activate", ['active' => true])->assertOk();
        return $id;
    }

    private function createOrder(User $user, int $businessId, int $bomId, int $branchId, int $warehouseId, float $quantity): int
    {
        return $this->actingAs($user)->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/manufacturing/orders', ['bom_id' => $bomId, 'branch_id' => $branchId, 'source_warehouse_id' => $warehouseId, 'finished_goods_warehouse_id' => $warehouseId, 'planned_quantity' => $quantity])
            ->assertCreated()
            ->json('order.id');
    }
}
