<?php

namespace Tests\Feature;

use App\Models\HsnMaster;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseTaxSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_item_snapshots_current_business_product_hsn_and_gst(): void
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => 'BillIQ Purchase Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        $branchId = DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouseId = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branchId, 'name' => 'Store', 'code' => 'ST', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $supplierId = DB::table('suppliers')->insertGetId(['business_id' => $businessId, 'supplier_name' => 'Acme Supplier', 'name' => 'Acme Supplier', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $hsn = HsnMaster::query()->create([
            'business_id' => null,
            'code_type' => 'HSN',
            'hsn_code' => '1514',
            'description' => 'Mustard oil',
            'taxability' => 'taxable',
            'gst_rate' => 5,
            'cess_rate' => 0,
            'status' => 'active',
        ]);
        $product = Product::query()->create([
            'business_id' => $businessId,
            'company_id' => $businessId,
            'name' => 'Fortune Mustard Oil 1L',
            'sku' => 'MO-001',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'track_inventory' => true,
            'tracking_type' => 'none',
            'unit' => 'PCS',
            'hsn_master_id' => $hsn->id,
            'hsn_id' => $hsn->id,
            'hsn_code' => '1514',
            'hsn' => '1514',
            'taxability' => 'taxable',
            'gst_rate' => 18,
            'cess_rate' => 0,
            'tax_source' => 'override',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->postJson('/app/purchase/bills', [
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'supplier_id' => $supplierId,
                'purchase_date' => now()->toDateString(),
                'purchase_type' => 'credit',
                'tax_type' => 'intrastate',
                'paid_amount' => 0,
                'status' => 'draft',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'purchase_rate' => 100,
                    'gst_rate' => 18,
                    'cess_rate' => 0,
                ]],
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('purchase_items', [
            'product_id' => $product->id,
            'hsn_code_snapshot' => '1514',
            'hsn_code_type_snapshot' => 'HSN',
            'hsn_tax_rate_id' => null,
            'taxability_snapshot' => 'taxable',
            'tax_source' => 'override',
            'gst_rate' => 18,
            'cgst_rate' => 9,
            'sgst_rate' => 9,
        ]);

        $hsn->update(['gst_rate' => 5]);
        $product->update(['gst_rate' => 5]);

        $this->assertDatabaseHas('purchase_items', [
            'product_id' => $product->id,
            'hsn_code_snapshot' => '1514',
            'gst_rate' => 18,
        ]);
    }
}
