<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesVoucher;
use App\Models\User;
use App\Services\CustomerAnalyticsService;
use App\Services\MobileNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerCrmWhatsappTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_numbers_are_normalized_for_lookup_and_whatsapp(): void
    {
        $mobileNumbers = app(MobileNumberService::class);

        $this->assertSame('9876543210', $mobileNumbers->normalize('+91 98765 43210'));
        $this->assertSame('9876543210', $mobileNumbers->normalize('09876543210'));
        $this->assertTrue($mobileNumbers->isValidIndianMobile('98765-43210'));
        $this->assertSame('919876543210', $mobileNumbers->waMeNumber('98765-43210'));
    }

    public function test_customer_lookup_finds_active_customer_by_normalized_mobile(): void
    {
        [$businessId, $user] = $this->businessAndUser();

        Customer::query()->create([
            'business_id' => $businessId,
            'customer_code' => 'CUS-00001',
            'customer_name' => 'Asha Traders',
            'customer_type' => 'retail',
            'mobile' => '+91 98765 43210',
            'normalized_mobile' => '9876543210',
            'whatsapp_number' => '9876543210',
            'whatsapp_same_as_mobile' => true,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/sales/customers/lookup?mobile=09876543210')
            ->assertOk()
            ->assertJsonPath('status', 'found')
            ->assertJsonPath('customer.customer_name', 'Asha Traders')
            ->assertJsonPath('customer.normalized_mobile', '9876543210');
    }

    public function test_customer_analytics_uses_only_posted_sales_and_tracks_last_product_purchase(): void
    {
        [$businessId] = $this->businessAndUser();
        [$customer, $product] = $this->customerAndProduct($businessId);

        $this->invoiceWithItem($businessId, $customer, $product, 'INV-001', 'confirmed', '2026-08-01', 500, 180);
        $this->invoiceWithItem($businessId, $customer, $product, 'INV-002', 'draft', '2026-08-10', 900, 300);

        $analytics = app(CustomerAnalyticsService::class);
        $summary = $analytics->summary($customer);
        $lastPurchase = $analytics->lastProductPurchase($customer, $product->id);

        $this->assertSame(1, $summary['total_orders']);
        $this->assertSame(500.0, $summary['lifetime_sales']);
        $this->assertSame('2026-08-01', $lastPurchase['invoice_date']);
        $this->assertSame(180.0, $lastPurchase['selling_rate']);
    }

    public function test_whatsapp_share_creates_public_invoice_token_and_log_for_posted_invoice(): void
    {
        [$businessId, $user] = $this->businessAndUser();
        [$customer, $product] = $this->customerAndProduct($businessId);
        $invoice = $this->invoiceWithItem($businessId, $customer, $product, 'INV-003', 'approved', '2026-08-13', 360, 360);

        $response = $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->postJson("/app/sales/invoices/{$invoice->id}/whatsapp", [
                'whatsapp_number' => '9876543210',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('provider', 'deep_link');

        $invoice->refresh();

        $this->assertNotNull($invoice->public_token);
        $this->assertTrue((bool) $invoice->public_share_enabled);
        $this->assertStringContainsString('https://wa.me/919876543210?text=', $response->json('url'));
        $this->assertDatabaseHas('document_share_logs', [
            'business_id' => $businessId,
            'customer_id' => $customer->id,
            'sales_voucher_id' => $invoice->id,
            'channel' => 'whatsapp',
            'recipient' => '919876543210',
            'status' => 'initiated',
            'provider' => 'deep_link',
        ]);

        $this->get('/i/not-a-real-token')->assertNotFound();
    }

    private function businessAndUser(): array
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create(['role_id' => 2, 'is_active' => 1, 'status' => 'active']);

        return [$businessId, $user];
    }

    private function customerAndProduct(int $businessId): array
    {
        $customer = Customer::query()->create([
            'business_id' => $businessId,
            'customer_code' => 'CUS-' . str_pad((string) $businessId, 5, '0', STR_PAD_LEFT),
            'customer_name' => 'Walkup Retail',
            'customer_type' => 'retail',
            'mobile' => '9876543210',
            'normalized_mobile' => '9876543210',
            'whatsapp_number' => '9876543210',
            'whatsapp_same_as_mobile' => true,
            'status' => 'active',
        ]);

        $product = Product::query()->create([
            'company_id' => $businessId,
            'business_id' => $businessId,
            'name' => 'Honey 500gm',
            'product_name' => 'Honey 500gm',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'unit' => 'PCS',
            'sku' => 'HONEY-' . $businessId,
            'hsn_code' => '0409',
            'hsn' => '0409',
            'taxability' => 'taxable',
            'gst_rate' => 5,
            'selling_price' => 360,
            'sale_price' => 360,
            'tracking_type' => 'none',
            'status' => 'active',
        ]);

        return [$customer, $product];
    }

    private function invoiceWithItem(
        int $businessId,
        Customer $customer,
        Product $product,
        string $invoiceNumber,
        string $status,
        string $date,
        float $total,
        float $rate
    ): SalesVoucher {
        $invoice = SalesVoucher::query()->create([
            'business_id' => $businessId,
            'customer_id' => $customer->id,
            'voucher_number' => $invoiceNumber,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $date,
            'customer_name_snapshot' => $customer->customer_name,
            'customer_mobile_snapshot' => $customer->mobile,
            'subtotal' => $total,
            'taxable_amount' => $total,
            'grand_total' => $total,
            'paid_amount' => $total,
            'balance_amount' => 0,
            'payment_status' => 'paid',
            'status' => $status,
        ]);

        SalesItem::query()->create([
            'sales_voucher_id' => $invoice->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'quantity' => 1,
            'selling_rate' => $rate,
            'taxable_amount' => $rate,
            'gst_rate' => $product->gst_rate,
            'line_total' => $rate,
        ]);

        return $invoice;
    }
}
