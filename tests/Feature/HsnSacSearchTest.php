<?php

namespace Tests\Feature;

use App\Models\HsnMaster;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HsnSacSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_goods_search_returns_hsn_and_not_sac(): void
    {
        $businessId = $this->business();
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);

        HsnMaster::query()->create(['hsn_code' => '1514', 'code_type' => 'HSN', 'description' => 'Rapeseed mustard or colza oil', 'status' => 'active', 'classification_verified' => true]);
        HsnMaster::query()->create(['hsn_code' => '998314', 'code_type' => 'SAC', 'description' => 'Information technology consulting services', 'status' => 'active', 'classification_verified' => true]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=mustard&product_type=goods')
            ->assertOk()
            ->assertJsonPath('0.code_type', 'HSN')
            ->assertJsonMissing(['code_type' => 'SAC']);
    }

    public function test_service_search_returns_sac_and_not_hsn(): void
    {
        $businessId = $this->business();
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);

        HsnMaster::query()->create(['hsn_code' => '8517', 'code_type' => 'HSN', 'description' => 'Mobile phones', 'status' => 'active', 'classification_verified' => true]);
        HsnMaster::query()->create(['hsn_code' => '998314', 'code_type' => 'SAC', 'description' => 'Information technology consulting services', 'status' => 'active', 'classification_verified' => true]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=consulting&product_type=service')
            ->assertOk()
            ->assertJsonPath('0.code_type', 'SAC')
            ->assertJsonMissing(['code_type' => 'HSN']);
    }

    public function test_exact_code_and_similar_product_ranking(): void
    {
        $businessId = $this->business();
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        $oil = HsnMaster::query()->create(['hsn_code' => '1514', 'code_type' => 'HSN', 'description' => 'Rapeseed mustard or colza oil', 'status' => 'active', 'classification_verified' => true]);
        HsnMaster::query()->create(['hsn_code' => '1509', 'code_type' => 'HSN', 'description' => 'Olive oil', 'status' => 'active', 'classification_verified' => true]);

        Product::query()->create([
            'company_id' => $businessId,
            'business_id' => $businessId,
            'name' => 'Fortune Mustard Oil 5L',
            'sku' => 'OIL-5L',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'hsn_master_id' => $oil->id,
            'hsn_id' => $oil->id,
            'hsn_code' => '1514',
            'hsn' => '1514',
            'gst_rate' => 5,
            'selling_price' => 100,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=mustard oil&product_type=goods&product_name=Fortune Mustard Oil 1L')
            ->assertOk()
            ->assertJsonPath('0.hsn_code', '1514')
            ->assertJsonPath('0.match_source', 'similar_product');
    }

    public function test_tax_resolution_statuses_and_null_rate_is_not_zero(): void
    {
        $businessId = $this->business();
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        $hsn = HsnMaster::query()->create(['hsn_code' => '8471', 'code_type' => 'HSN', 'description' => 'Laptop computers', 'gst_rate' => null, 'status' => 'active', 'classification_verified' => true]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=8471&product_type=goods')
            ->assertOk()
            ->assertJsonPath('0.gst_rate', null)
            ->assertJsonPath('0.tax_resolution.status', 'no_verified_rule');

        DB::table('hsn_tax_rates')->insert([
            'hsn_id' => $hsn->id,
            'rule_name' => 'Laptop standard rule',
            'taxability' => 'taxable',
            'gst_rate' => 18,
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'cess_rate' => 0,
            'effective_from' => '2026-01-01',
            'verification_status' => 'verified',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=8471&product_type=goods&transaction_date=2026-08-01')
            ->assertOk()
            ->assertJsonPath('0.tax_resolution.status', 'single_verified_rule')
            ->assertJsonPath('0.tax_resolution.rule.gst_rate', 18);
    }

    private function business(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
