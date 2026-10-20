<?php

namespace Tests\Feature;

use App\Http\Controllers\AppController;
use App\Models\TallyConnection;
use App\Models\TallySyncBatch;
use App\Models\User;
use App\Services\TallyIntegrationService;
use App\Services\TallyPostingImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TallyDemoEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_envelope_runs_through_real_ingest_approval_import_and_reconciliation(): void
    {
        [$businessId, $user, $branchId, $warehouseId, $productId, $customerId, $supplierId] = $this->prepareDemoCompany();
        $this->actingAs($user)->withSession(['business_id' => $businessId, 'company_id' => $businessId, 'branch_id' => $branchId]);
        $connection = TallyConnection::create(['business_id' => $businessId, 'name' => 'Demo Tally', 'location' => 'local', 'status' => 'connected']);
        [$records, $company] = $this->fixtureRecords();

        $service = app(TallyIntegrationService::class);
        $batch = $service->ingest($connection, ['mode' => 'full', 'company_name' => $company['name'], 'company_guid' => $company['guid'], 'date_from' => '2026-04-01', 'date_to' => '2026-04-30', 'records' => $records], 'demo-e2e-1');
        $this->assertSame(17, $batch->records_received);
        $this->assertSame(17, $batch->records_ready);
        $this->assertSame(1, DB::table('customers')->where('business_id', $businessId)->where('customer_name', 'ABC Retailers')->count());
        $this->assertSame(1, DB::table('products')->where('business_id', $businessId)->where('name', 'Test Product A')->count());

        $approved = $service->approve($batch, $user->id);
        $this->assertSame('approved', $approved->status);
        $imported = app(TallyPostingImportService::class)->import($approved, $user->id);
        $this->assertSame('imported', $imported->status);

        $this->assertDatabaseCount('sales_vouchers', 1);
        $this->assertDatabaseCount('purchase_vouchers', 1);
        $this->assertDatabaseHas('sales_vouchers', ['customer_id' => $customerId, 'reference_number' => 'S-1001']);
        $this->assertDatabaseHas('purchase_vouchers', ['supplier_id' => $supplierId]);
        $this->assertDatabaseHas('purchase_vouchers', ['supplier_invoice_number' => 'P-1001']);
        $this->assertSame(2, DB::table('journal_vouchers')->where('business_id', $businessId)->whereIn('voucher_type', ['sales', 'purchase'])->count());

        $journalTotals = DB::table('journal_vouchers')->where('business_id', $businessId)->selectRaw('SUM(total_debit) debit, SUM(total_credit) credit')->first();
        $this->assertEqualsWithDelta((float) $journalTotals->debit, (float) $journalTotals->credit, 0.01);
        $this->assertEqualsWithDelta(22420.0, (float) $journalTotals->debit, 0.01);
        $this->assertEqualsWithDelta(900.0, (float) DB::table('journal_entries')->where('business_id', $businessId)->whereIn('account_id', DB::table('accounts')->where('business_id', $businessId)->whereIn('account_name', ['Output CGST', 'Output SGST'])->pluck('id'))->sum('credit_amount'), 0.01);

        $stock = DB::table('stock_ledgers')->where('business_id', $businessId)->where('product_id', $productId)->sum(DB::raw('quantity_in - quantity_out'));
        $this->assertEqualsWithDelta(115.0, (float) $stock, 0.001);
        $this->assertEqualsWithDelta(115.0, (float) DB::table('products')->where('id', $productId)->value('current_stock'), 0.001);
        $this->assertSame(1, DB::table('opening_stock_vouchers')->where('business_id', $businessId)->where('status', 'posted')->count());

        $reconciliation = collect($service->reconcile($imported))->keyBy('category');
        $this->assertSame('match', $reconciliation['sales_total']['status']);
        $this->assertSame('match', $reconciliation['purchase_total']['status']);
        $this->assertSame('match', $reconciliation['gst_total']['status']);
        $this->assertSame('mismatch', $reconciliation['customer_outstanding']['status']);
        $this->assertSame('mismatch', $reconciliation['supplier_outstanding']['status']);
        $this->assertSame('match', $reconciliation['stock_quantity']['status']);
        $this->assertSame('unavailable', $reconciliation['inventory_valuation']['status']);

        $secondBatch = $service->ingest($connection, ['mode' => 'full', 'company_name' => $company['name'], 'company_guid' => $company['guid'], 'records' => $records], 'demo-e2e-2');
        $service->approve($secondBatch, $user->id);
        app(TallyPostingImportService::class)->import($secondBatch, $user->id);
        $this->assertDatabaseCount('sales_vouchers', 1);
        $this->assertDatabaseCount('purchase_vouchers', 1);
        $this->assertDatabaseCount('tally_source_records', 17);
        $this->assertSame(1, DB::table('customers')->where('business_id', $businessId)->where('customer_name', 'ABC Retailers')->count());
        $this->assertSame(1, DB::table('products')->where('business_id', $businessId)->where('name', 'Test Product A')->count());
    }

    public function test_unbalanced_voucher_is_staged_as_error_and_cannot_be_approved(): void
    {
        [$businessId, $user] = $this->prepareDemoCompany(false);
        $this->actingAs($user)->withSession(['business_id' => $businessId, 'company_id' => $businessId]);
        $connection = TallyConnection::create(['business_id' => $businessId, 'name' => 'Demo Tally', 'location' => 'local', 'status' => 'connected']);
        $badXml = '<VOUCHER><GUID>bad-voucher-1</GUID><DATE>20260401</DATE><VOUCHERTYPENAME>Journal</VOUCHERTYPENAME><VOUCHERNUMBER>BAD-1</VOUCHERNUMBER><ALLLEDGERENTRIES.LIST><LEDGERNAME>Sales</LEDGERNAME><ISDEEMEDPOSITIVE>YES</ISDEEMEDPOSITIVE><AMOUNT>100</AMOUNT></ALLLEDGERENTRIES.LIST></VOUCHER>';
        $batch = app(TallyIntegrationService::class)->ingest($connection, ['records' => [['type' => 'voucher', 'key' => 'bad-voucher-1', 'payload' => ['xml' => $badXml]]]], 'bad-e2e-1');
        $this->assertSame('error', $batch->records()->first()->status);
        $this->expectException(ValidationException::class);
        app(TallyIntegrationService::class)->approve($batch, $user->id);
    }

    public function test_missing_ledger_mapping_rolls_back_the_entire_financial_import(): void
    {
        [$businessId, $user] = $this->prepareDemoCompany(false);
        $this->actingAs($user)->withSession(['business_id' => $businessId, 'company_id' => $businessId]);
        $connection = TallyConnection::create(['business_id' => $businessId, 'name' => 'Demo Tally', 'location' => 'local', 'status' => 'connected']);
        $xml = '<VOUCHER><GUID>missing-ledger-1</GUID><DATE>20260401</DATE><VOUCHERTYPENAME>Sales</VOUCHERTYPENAME><VOUCHERNUMBER>MISSING-1</VOUCHERNUMBER><PARTYLEDGERNAME>ABC Retailers</PARTYLEDGERNAME><ALLLEDGERENTRIES.LIST><LEDGERNAME>Missing Ledger</LEDGERNAME><ISDEEMEDPOSITIVE>NO</ISDEEMEDPOSITIVE><AMOUNT>-100</AMOUNT></ALLLEDGERENTRIES.LIST><ALLINVENTORYENTRIES.LIST><STOCKITEMNAME>Test Product A</STOCKITEMNAME><ACTUALQTY>-1 Nos</ACTUALQTY><RATE>100</RATE><AMOUNT>-100</AMOUNT></ALLINVENTORYENTRIES.LIST></VOUCHER>';
        $service = app(TallyIntegrationService::class);
        $batch = $service->ingest($connection, ['records' => [['type' => 'voucher', 'key' => 'missing-ledger-1', 'payload' => ['xml' => $xml]]]], 'missing-ledger-e2e');
        $approved = $service->approve($batch, $user->id);
        $this->expectException(ValidationException::class);
        try {
            app(TallyPostingImportService::class)->import($approved, $user->id);
        } finally {
            $this->assertDatabaseCount('sales_vouchers', 0);
            $this->assertDatabaseCount('journal_vouchers', 0);
            $this->assertDatabaseCount('stock_ledgers', 0);
        }
    }

    private function fixtureRecords(): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML(file_get_contents(base_path('tests/Fixtures/tally/demo-envelope.xml')));
        $xpath = new \DOMXPath($dom);
        $companyNode = $xpath->query('//*[local-name()="COMPANY"]')->item(0);
        $company = ['name' => trim($xpath->query('.//*[local-name()="NAME"]', $companyNode)->item(0)->textContent), 'guid' => trim($xpath->query('.//*[local-name()="GUID"]', $companyNode)->item(0)->textContent)];
        $records = [];
        foreach ($xpath->query('//*[local-name()="GROUP" or local-name()="LEDGER" or local-name()="UNIT" or local-name()="STOCKITEM" or local-name()="VOUCHER"]') as $node) {
            // Receipt/payment import requires explicit BillIQ invoice allocations. Keep those
            // records out of this auto-import demo; they remain staged for manual review.
            if ($node->localName === 'VOUCHER' && in_array(trim($xpath->query('.//*[local-name()="VOUCHERTYPENAME"]', $node)->item(0)?->textContent ?? ''), ['Receipt', 'Payment'], true)) continue;
            $type = match ($node->localName) { 'GROUP' => 'group', 'LEDGER' => 'ledger', 'UNIT' => 'unit', 'STOCKITEM' => 'stock_item', default => 'voucher' };
            $records[] = ['type' => $type, 'key' => trim($xpath->query('.//*[local-name()="GUID"]', $node)->item(0)->textContent), 'payload' => ['xml' => $dom->saveXML($node)]];
        }
        return [$records, $company];
    }

    private function prepareDemoCompany(bool $withMasters = true): array
    {
        $businessId = DB::table('companies')->insertGetId(['name' => 'Demo Trading Pvt Ltd', 'state' => 'UP', 'financial_year' => '2026-27', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['tenant_id' => $businessId, 'role_id' => 2, 'status' => 'active', 'is_active' => 1]);
        DB::table('company_user_access')->insert(['company_id' => $businessId, 'user_id' => $user->id, 'access_role' => 'admin', 'is_default' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $branchId = DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => 'Demo Branch', 'code' => 'DEMO', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouseId = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branchId, 'name' => 'Main Warehouse', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $unitId = DB::table('units')->insertGetId(['name' => 'Nos', 'code' => 'NOS', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $customerId = $this->insert('customers', ['business_id' => $businessId, 'company_id' => $businessId, 'customer_code' => 'CUS-DEMO', 'customer_name' => 'ABC Retailers', 'name' => 'ABC Retailers', 'opening_balance' => 0, 'opening_balance_type' => 'debit', 'status' => 'active']);
        $supplierId = $this->insert('suppliers', ['business_id' => $businessId, 'company_id' => $businessId, 'supplier_code' => 'SUP-DEMO', 'supplier_name' => 'XYZ Distributors', 'name' => 'XYZ Distributors', 'opening_balance' => 0, 'opening_balance_type' => 'credit', 'status' => 'active']);
        $productId = $this->insert('products', ['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Test Product A', 'sku' => 'DEMO-A', 'product_type' => 'goods', 'item_type' => 'stock', 'unit_id' => $unitId, 'opening_stock' => 0, 'current_stock' => 0, 'selling_price' => 1000, 'cost_price' => 700, 'gst_rate' => 18, 'track_inventory' => 1, 'status' => 'active']);
        return [$businessId, $user, $branchId, $warehouseId, $productId, $customerId, $supplierId];
    }

    private function insert(string $table, array $values): int
    {
        $values['created_at'] = now(); $values['updated_at'] = now();
        return DB::table($table)->insertGetId(array_intersect_key($values, array_flip(Schema::getColumnListing($table))));
    }
}
