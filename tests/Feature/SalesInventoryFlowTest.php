<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use App\Models\StockLedger;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\OrderManagementService;
use App\Services\SalesReturnService;
use App\Services\SalesService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesInventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_uses_available_to_sell_without_stock_ledger_movement(): void
    {
        [$businessId, $user, $product, $branch, $warehouse, $customer] = $this->fixture();
        $this->loginBusiness($user, $businessId);
        $this->openingStock($businessId, $branch, $warehouse, $product->id, 100);
        $this->order($businessId, $branch, $warehouse, $customer, $product->id, 90);

        $order = $this->order($businessId, $branch, $warehouse, $customer, $product->id, 20);
        app(OrderManagementService::class)->approveSalesOrder($order->id);

        $this->assertSame(10.0, (float) StockReservation::query()->where('reference_id', $order->id)->value('reserved_quantity'));
        $this->assertSame(100.0, app(StockService::class)->getCurrentStock(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product->id]));
        $this->assertSame(0, StockLedger::query()->where('transaction_type', 'stock_reservation')->count());
    }

    public function test_sale_consumes_customer_reservation_and_only_sale_reduces_stock(): void
    {
        [$businessId, $user, $product, $branch, $warehouse, $customer] = $this->fixture();
        $this->loginBusiness($user, $businessId);
        $this->openingStock($businessId, $branch, $warehouse, $product->id, 100);
        $this->order($businessId, $branch, $warehouse, $customer, $product->id, 20);

        $sale = $this->sale($businessId, $branch, $warehouse, $customer, $product->id, 15);
        app(SalesService::class)->post($sale);

        $this->assertSame(85.0, app(StockService::class)->getCurrentStock(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product->id]));
        $this->assertSame(15.0, (float) StockReservation::query()->value('fulfilled_quantity'));
        $this->assertSame(1, StockLedger::query()->where('reference_type', SalesVoucher::class)->where('reference_id', $sale->id)->where('transaction_type', 'sale')->count());
    }

    public function test_duplicate_sales_return_approval_posts_stock_once(): void
    {
        [$businessId, $user, $product, $branch, $warehouse, $customer] = $this->fixture();
        $this->loginBusiness($user, $businessId);
        $sale = $this->sale($businessId, $branch, $warehouse, $customer, $product->id, 5, 'approved');
        $return = $this->salesReturn($businessId, $branch, $warehouse, $customer, $sale);

        app(SalesReturnService::class)->post($return);
        app(SalesReturnService::class)->post($return->fresh());

        $this->assertSame(1, StockLedger::query()->where('reference_type', SalesReturnVoucher::class)->where('reference_id', $return->id)->where('transaction_type', 'sales_return')->count());
    }

    private function fixture(): array
    {
        $businessId = DB::table('companies')->insertGetId(['name' => 'Bill IQ Test', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['role_id' => 1, 'is_active' => 1, 'status' => 'active']);
        $branch = DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branch, 'name' => 'Store', 'code' => 'ST', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $customer = DB::table('customers')->insertGetId(['business_id' => $businessId, 'customer_name' => 'Acme Customer', 'mobile' => '9999999999', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Inventory Item', 'sku' => 'INV-1', 'product_type' => 'goods', 'item_type' => 'stock', 'track_inventory' => true, 'tracking_type' => 'none', 'status' => 'active']);

        return [$businessId, $user, $product, $branch, $warehouse, $customer];
    }

    private function loginBusiness(User $user, int $businessId): void
    {
        Auth::login($user);
        session(['business_id' => $businessId]);
    }

    private function openingStock(int $businessId, int $branch, int $warehouse, int $productId, float $quantity): void
    {
        StockLedger::query()->create(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $productId, 'transaction_type' => 'opening_stock', 'reference_type' => Product::class, 'reference_id' => $productId, 'quantity_in' => $quantity, 'quantity_out' => 0, 'unit_cost' => 10, 'stock_value' => $quantity * 10]);
    }

    private function order(int $businessId, int $branch, int $warehouse, int $customer, int $productId, float $quantity): SalesOrder
    {
        $order = SalesOrder::query()->create(['business_id' => $businessId, 'order_number' => 'SO-' . uniqid(), 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'customer_id' => $customer, 'order_date' => now()->toDateString(), 'order_status' => 'draft', 'reservation_status' => 'none', 'dispatch_status' => 'pending', 'invoice_status' => 'not_invoiced']);
        SalesOrderItem::query()->create(['sales_order_id' => $order->id, 'product_id' => $productId, 'ordered_quantity' => $quantity, 'unit_price' => 10, 'line_total' => $quantity * 10]);
        app(OrderManagementService::class)->approveSalesOrder($order->id);

        return $order->fresh();
    }

    private function sale(int $businessId, int $branch, int $warehouse, int $customer, int $productId, float $quantity, string $status = 'draft'): SalesVoucher
    {
        $sale = SalesVoucher::query()->create(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'customer_id' => $customer, 'voucher_number' => 'SV-' . uniqid(), 'invoice_number' => 'INV-' . uniqid(), 'invoice_date' => now()->toDateString(), 'sale_type' => 'cash', 'invoice_type' => 'tax_invoice', 'tax_type' => 'intrastate', 'subtotal' => $quantity * 10, 'taxable_amount' => $quantity * 10, 'grand_total' => $quantity * 10, 'paid_amount' => $quantity * 10, 'balance_amount' => 0, 'payment_status' => 'paid', 'status' => $status]);
        $sale->items()->create(['product_id' => $productId, 'product_name_snapshot' => 'Inventory Item', 'sku_snapshot' => 'INV-1', 'quantity' => $quantity, 'free_quantity' => 0, 'selling_rate' => 10, 'discount_amount' => 0, 'taxable_amount' => $quantity * 10, 'gst_rate' => 0, 'cgst_rate' => 0, 'sgst_rate' => 0, 'igst_rate' => 0, 'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0, 'cess_rate' => 0, 'cess_amount' => 0, 'line_total' => $quantity * 10, 'cost_rate' => 10]);

        return $sale->fresh(['items.product']);
    }

    private function salesReturn(int $businessId, int $branch, int $warehouse, int $customer, SalesVoucher $sale): SalesReturnVoucher
    {
        $return = SalesReturnVoucher::query()->create(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'customer_id' => $customer, 'sales_voucher_id' => $sale->id, 'voucher_number' => 'SR-' . uniqid(), 'credit_note_number' => 'CN-' . uniqid(), 'return_date' => now()->toDateString(), 'return_type' => 'against_sale', 'tax_type' => 'intrastate', 'subtotal' => 10, 'taxable_amount' => 10, 'grand_total' => 10, 'settlement_type' => 'customer_credit', 'balance_amount' => 10, 'status' => 'draft']);
        $return->items()->create(['sales_item_id' => $sale->items->first()->id, 'product_id' => $sale->items->first()->product_id, 'product_name_snapshot' => 'Inventory Item', 'sku_snapshot' => 'INV-1', 'quantity' => 1, 'selling_rate' => 10, 'discount_amount' => 0, 'taxable_amount' => 10, 'gst_rate' => 0, 'cgst_rate' => 0, 'sgst_rate' => 0, 'igst_rate' => 0, 'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0, 'cess_rate' => 0, 'cess_amount' => 0, 'line_total' => 10, 'restock_status' => 'restock']);

        return $return->fresh(['items.product']);
    }
}
