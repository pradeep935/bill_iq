<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseProductSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_search_matches_supported_fields_and_returned_product_id(): void
    {
        $businessId = $this->signIn();
        $product = $this->product($businessId, ['name' => 'honey 500gm', 'short_name' => 'Sweet jar', 'sku' => 'H', 'barcode' => 'LEGACY123', 'primary_barcode' => '890000000072']);
        $product->barcodes()->create(['business_id' => $businessId, 'barcode' => 'SECONDARY123']);
        foreach (['honey', 'honey 500gm', 'Sweet', 'H', '890000000072', 'LEGACY123', 'SECONDARY123', 'id:'.$product->id] as $search) {
            $this->getJson('/app/purchase/bills/products/search?'.http_build_query(['q' => $search]))
                ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $product->id)
                ->assertJsonStructure([['variants', 'batches', 'current_stock', 'purchase_rate']]);
        }
        foreach (['', 'completely unknown product'] as $search) {
            $this->getJson('/app/purchase/bills/products/search?'.http_build_query(['q' => $search]))->assertOk()->assertExactJson([]);
        }
    }

    public function test_search_preserves_stock_rules_tracking_and_tenant_isolation(): void
    {
        $businessId = $this->signIn();
        $otherId = DB::table('companies')->insertGetId(['name' => 'Other']);
        $batch = $this->product($businessId, ['tracking_type' => 'batch', 'batch_required' => true]);
        $serial = $this->product($businessId, ['tracking_type' => 'serial', 'serial_required' => true]);
        $legacy = $this->product($businessId, ['business_id' => null]);
        $this->product($businessId, ['status' => 'inactive']);
        $this->product($businessId, ['item_type' => 'non_stock']);
        $this->product($businessId, ['product_type' => 'service', 'item_type' => 'non_stock']);
        $this->product($businessId)->delete();
        $foreign = $this->product($otherId, ['company_id' => $businessId]);
        $response = $this->getJson('/app/purchase/bills/products/search?q=honey')->assertOk()->assertJsonCount(3);
        $this->assertEqualsCanonicalizing([$batch->id, $serial->id, $legacy->id], array_column($response->json(), 'id'));
        $this->getJson('/app/purchase/bills/products/search?q=id:'.$foreign->id)->assertExactJson([]);
    }

    public function test_results_are_bounded(): void
    {
        $businessId = $this->signIn();
        for ($i = 0; $i < 25; $i++) $this->product($businessId);
        $this->getJson('/app/purchase/bills/products/search?q=honey')->assertOk()->assertJsonCount(20);
    }

    public function test_totals_preview_reuses_purchase_calculator_without_saving(): void
    {
        $this->signIn();
        $input = [
            'tax_type' => 'intrastate', 'discount_type' => 'amount',
            'discount_value' => 10, 'paid_amount' => 100,
            'items' => [['quantity' => 2, 'purchase_rate' => 250,
                'discount_type' => 'percentage', 'discount_value' => 5,
                'gst_rate' => 5, 'cess_rate' => 0]],
        ];
        $before = DB::table('purchase_vouchers')->count();
        $expected = app(\App\Services\PurchaseCalculationService::class)->calculateVoucherTotals($input);
        $this->postJson('/app/purchase/bills/preview-totals', $input)
            ->assertOk()->assertExactJson($expected);
        $this->assertSame($before, DB::table('purchase_vouchers')->count());
        $this->postJson('/app/purchase/bills/preview-totals', ['tax_type' => 'invalid', 'items' => []])
            ->assertUnprocessable();
    }

    private function signIn(): int
    {
        $id = DB::table('companies')->insertGetId(['name' => 'Search Test']);
        $this->actingAs(User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']))
            ->withSession(['business_id' => $id]);
        return $id;
    }

    private function product(int $businessId, array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'business_id' => $businessId, 'company_id' => $businessId,
            'name' => 'honey test', 'sku' => uniqid('SKU-'), 'unit' => 'PCS',
            'product_type' => 'goods', 'item_type' => 'stock', 'status' => 'active',
        ], $attributes));
    }
}
