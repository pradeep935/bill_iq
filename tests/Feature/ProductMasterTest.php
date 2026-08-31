<?php

namespace Tests\Feature;

use App\Models\HsnMaster;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductMasterTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_creation_saves_simple_master_fields(): void
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

        $response = $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/products/save', [
                'name' => 'Britannia Bread',
                'product_type' => 'goods',
                'item_type' => 'stock',
                'category' => 'Bakery',
                'subcategory' => 'Bread',
                'brand' => 'Britannia',
                'variant' => 'Small Pack',
                'unit' => 'PCS',
                'hsn_master_id' => $hsn->id,
                'hsn_code' => '1905',
                'taxability' => 'taxable',
                'gst_rate' => 5,
                'cess_rate' => 0,
                'reverse_charge' => 'no',
                'tax_inclusive' => false,
                'selling_price' => 40,
                'cost_price' => 30,
                'mrp' => 45,
                'wholesale_price' => 36,
                'dealer_price' => 35,
                'online_price' => 38,
                'opening_stock' => 10,
                'minimum_stock' => 3,
                'reorder_stock' => 15,
                'maximum_stock' => 100,
                'tracking_type' => 'batch',
                'sku' => 'BREAD-01',
                'primary_barcode' => '8901063400123',
                'extra_barcodes' => '8901063400124',
                'batch_required' => true,
                'expiry_required' => false,
                'serial_required' => false,
                'status' => 'active',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('product.opening_stock', 0)
            ->assertJsonPath('product.primary_barcode', '8901063400123');

        $this->assertDatabaseHas('products', [
            'business_id' => $businessId,
            'company_id' => $businessId,
            'sku' => 'BREAD-01',
            'primary_barcode' => '8901063400123',
            'sale_price' => 40,
            'purchase_price' => 30,
        ]);
        $this->assertDatabaseHas('product_prices', ['product_id' => $response->json('product.id'), 'price_type' => 'Wholesale', 'price' => 36]);
        $this->assertDatabaseHas('product_barcodes', ['business_id' => $businessId, 'barcode' => '8901063400123']);
    }

    public function test_product_gst_override_does_not_update_global_hsn_reference(): void
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
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

        $response = $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/products/save', [
                'name' => 'Fortune Mustard Oil 1L',
                'product_type' => 'goods',
                'item_type' => 'stock',
                'unit' => 'PCS',
                'sku' => 'MO-001',
                'hsn_master_id' => $hsn->id,
                'hsn_code' => '1514',
                'taxability' => 'taxable',
                'gst_rate' => 18,
                'cess_rate' => 0,
                'tax_source' => 'override',
                'tax_override_reason' => 'Rate confirmed by CA',
                'reverse_charge' => 'no',
                'tax_inclusive' => false,
                'selling_price' => 180,
                'cost_price' => 150,
                'mrp' => 190,
                'opening_stock' => 0,
                'minimum_stock' => 0,
                'reorder_stock' => 0,
                'maximum_stock' => 0,
                'tracking_type' => 'none',
                'batch_required' => false,
                'expiry_required' => false,
                'serial_required' => false,
                'status' => 'active',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('products', [
            'business_id' => $businessId,
            'sku' => 'MO-001',
            'hsn_master_id' => $hsn->id,
            'hsn_code' => '1514',
            'gst_rate' => 18,
            'tax_source' => 'override',
        ]);
        $this->assertDatabaseHas('hsn_masters', [
            'id' => $hsn->id,
            'business_id' => null,
            'gst_rate' => 5,
        ]);
    }

    public function test_business_admin_cannot_modify_global_hsn_reference(): void
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
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

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->putJson("/app/masters/hsn/{$hsn->id}", [
                'rate_update_only' => true,
                'taxability' => 'taxable',
                'gst_rate' => 18,
                'cess_rate' => 0,
                'effective_from' => now()->toDateString(),
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('hsn_masters', [
            'id' => $hsn->id,
            'gst_rate' => 5,
        ]);
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
            ->postJson('/app/inventory/products/save', [
                'name' => 'MRP Item',
                'product_type' => 'goods',
                'item_type' => 'stock',
                'category' => null,
                'brand' => null,
                'unit' => 'PCS',
                'sku' => 'MRP-01',
                'hsn_code' => '1905',
                'taxability' => 'taxable',
                'gst_rate' => 18,
                'cess_rate' => 0,
                'reverse_charge' => 'no',
                'tax_inclusive' => false,
                'tracking_type' => 'none',
                'selling_price' => 120,
                'cost_price' => 0,
                'mrp' => 100,
                'batch_required' => false,
                'expiry_required' => false,
                'serial_required' => false,
                'status' => 'active',
            ]);

        $response->assertUnprocessable();
    }

    public function test_product_can_be_updated_and_listed(): void
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        $product = Product::query()->create([
            'company_id' => $businessId,
            'business_id' => $businessId,
            'name' => 'Old Product',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'unit' => 'PCS',
            'sku' => 'OLD-01',
            'hsn_code' => '1001',
            'hsn' => '1001',
            'taxability' => 'taxable',
            'gst_rate' => 5,
            'selling_price' => 100,
            'sale_price' => 100,
            'tracking_type' => 'none',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->postJson('/app/inventory/products/save', [
                'id' => $product->id,
                'name' => 'Updated Product',
                'product_type' => 'service',
                'item_type' => 'non_stock',
                'category' => 'Consulting',
                'brand' => null,
                'variant' => null,
                'unit' => 'HRS',
                'sku' => 'OLD-01',
                'hsn_code' => '9983',
                'taxability' => 'taxable',
                'gst_rate' => 18,
                'cess_rate' => 0,
                'reverse_charge' => 'no',
                'tax_inclusive' => false,
                'selling_price' => 150,
                'cost_price' => 0,
                'mrp' => null,
                'opening_stock' => 0,
                'minimum_stock' => 0,
                'reorder_stock' => 0,
                'tracking_type' => 'none',
                'batch_required' => false,
                'expiry_required' => false,
                'serial_required' => false,
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('product.name', 'Updated Product')
            ->assertJsonPath('product.product_type', 'service');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/products/list')
            ->assertOk()
            ->assertJsonPath('products.0.name', 'Updated Product')
            ->assertJsonPath('products.0.status', 'inactive');
    }

    public function test_product_list_searches_product_name_and_filters_by_dropdown_values(): void
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        $categoryId = DB::table('product_categories')->insertGetId([
            'business_id' => $businessId,
            'name' => 'Grocery',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $brandId = DB::table('brands')->insertGetId([
            'business_id' => $businessId,
            'name' => 'Harvest',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Product::query()->create([
            'company_id' => $businessId,
            'business_id' => $businessId,
            'name' => 'Plain Rice',
            'product_name' => 'Golden Basmati Rice',
            'category' => 'Grocery',
            'brand' => 'Harvest',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'unit' => 'PCS',
            'sku' => 'RICE-01',
            'hsn_code' => '1006',
            'hsn' => '1006',
            'taxability' => 'taxable',
            'gst_rate' => 5,
            'selling_price' => 100,
            'sale_price' => 100,
            'tracking_type' => 'none',
            'status' => 'active',
        ]);
        Product::query()->create([
            'company_id' => $businessId,
            'business_id' => $businessId,
            'name' => 'Desk Lamp',
            'product_name' => 'Desk Lamp',
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'category' => 'Grocery',
            'brand' => 'Harvest',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'unit' => 'PCS',
            'sku' => 'LAMP-01',
            'hsn_code' => '9405',
            'hsn' => '9405',
            'taxability' => 'taxable',
            'gst_rate' => 18,
            'selling_price' => 400,
            'sale_price' => 400,
            'tracking_type' => 'none',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/products/list?search=Golden')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.sku', 'RICE-01');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/products/list?category=' . $categoryId)
            ->assertOk()
            ->assertJsonCount(2, 'products');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/products/list?brand=' . $brandId)
            ->assertOk()
            ->assertJsonCount(2, 'products');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/products/list?unit=PCS')
            ->assertOk()
            ->assertJsonCount(2, 'products');
    }

    public function test_sku_and_barcode_are_unique_per_business(): void
    {
        $firstBusinessId = DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondBusinessId = DB::table('companies')->insertGetId([
            'name' => 'XYZ Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);

        Product::query()->create([
            'company_id' => $firstBusinessId,
            'business_id' => $firstBusinessId,
            'name' => 'Existing Product',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'unit' => 'PCS',
            'sku' => 'DUP-01',
            'barcode' => 'BAR-01',
            'primary_barcode' => 'BAR-01',
            'hsn_code' => '1001',
            'hsn' => '1001',
            'taxability' => 'taxable',
            'gst_rate' => 5,
            'selling_price' => 100,
            'sale_price' => 100,
            'tracking_type' => 'none',
            'status' => 'active',
        ]);

        $payload = [
            'name' => 'New Product',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'unit' => 'PCS',
            'sku' => 'DUP-01',
            'primary_barcode' => 'BAR-01',
            'hsn_code' => '1001',
            'taxability' => 'taxable',
            'gst_rate' => 5,
            'cess_rate' => 0,
            'reverse_charge' => 'no',
            'tax_inclusive' => false,
            'selling_price' => 100,
            'cost_price' => 80,
            'tracking_type' => 'none',
            'status' => 'active',
        ];

        $this->actingAs($user)
            ->withSession(['business_id' => $firstBusinessId])
            ->postJson('/app/inventory/products/save', $payload)
            ->assertUnprocessable();

        $this->actingAs($user)
            ->withSession(['business_id' => $secondBusinessId])
            ->postJson('/app/inventory/products/save', $payload)
            ->assertCreated();
    }
}
