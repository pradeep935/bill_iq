<?php

namespace Tests\Feature;

use App\Models\HsnMaster;
use App\Models\HsnTaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_creation_posts_opening_stock_and_barcode(): void
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        $hsn = HsnMaster::query()->create([
            'hsn_code' => '1905',
            'description' => 'Bread and bakery',
            'gst_rate' => 5,
            'status' => 'active',
        ]);
        HsnTaxRate::query()->create([
            'hsn_id' => $hsn->id,
            'gst_rate' => 5,
            'cess_rate' => 0,
            'effective_from' => '2024-04-01',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/products', [
                'name' => 'Britannia Bread',
                'product_type' => 'goods',
                'sku' => 'BREAD-01',
                'category_name' => 'Bakery',
                'brand_name' => 'Britannia',
                'unit_code' => 'PCS',
                'hsn_code' => '1905',
                'tracking_type' => 'batch',
                'default_selling_price' => 40,
                'default_purchase_price' => 30,
                'mrp' => 45,
                'status' => 'active',
                'barcodes' => [['barcode' => '8901063400123', 'is_primary' => true]],
                'opening_stock' => ['quantity' => 10, 'unit_cost' => 30],
            ]);

        $response->assertCreated()->assertJsonPath('product.stock', 10);
        $this->assertDatabaseHas('product_barcodes', ['business_id' => $businessId, 'barcode' => '8901063400123']);
        $this->assertDatabaseHas('stock_ledgers', ['business_id' => $businessId, 'transaction_type' => 'opening_stock']);
    }

    public function test_selling_price_cannot_exceed_mrp(): void
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);

        $response = $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/products', [
                'name' => 'MRP Item',
                'product_type' => 'goods',
                'sku' => 'MRP-01',
                'unit_code' => 'PCS',
                'hsn_code' => '1905',
                'tracking_type' => 'none',
                'default_selling_price' => 120,
                'mrp' => 100,
                'status' => 'active',
            ]);

        $response->assertUnprocessable();
    }
}
