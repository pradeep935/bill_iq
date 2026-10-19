<?php

namespace Tests\Unit;

use App\Services\TallyXmlNormalizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TallyXmlNormalizerTest extends TestCase
{
    public function test_ledger_fixture_preserves_identity_and_master_fields(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/tally/ledger.xml'));
        $row = (new TallyXmlNormalizer())->normalize(['type' => 'ledger', 'payload' => ['xml' => $xml]]);

        $this->assertSame('customer', $row['type']);
        $this->assertSame('ledger-ravi-001', $row['key']);
        $this->assertSame('Ravi Traders', $row['name']);
        $this->assertSame('Sundry Debtors', $row['payload']['parent']);
        $this->assertSame(1250.0, $row['payload']['opening_balance']);
    }

    public function test_sales_fixture_extracts_voucher_type_allocations_and_balance(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/tally/sales-voucher.xml'));
        $row = (new TallyXmlNormalizer())->normalize(['type' => 'voucher', 'payload' => ['xml' => $xml]]);

        $this->assertSame('sales', $row['type']);
        $this->assertSame('2026-04-01', $row['date']);
        $this->assertSame('S-1001', $row['number']);
        $this->assertSame(1180.0, $row['debit']);
        $this->assertSame(1180.0, $row['credit']);
        $this->assertTrue($row['payload']['tally_totals_balanced']);
        $this->assertSame('Widget A', $row['payload']['inventory_allocations'][0]['item_name']);
    }

    public function test_external_entities_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new TallyXmlNormalizer())->normalize(['type' => 'ledger', 'payload' => ['xml' => '<!DOCTYPE foo [<!ENTITY x SYSTEM "file:///secret">]><LEDGER><NAME>&x;</NAME></LEDGER>']]);
    }

    public function test_unknown_voucher_type_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new TallyXmlNormalizer())->normalize(['type' => 'voucher', 'payload' => ['xml' => '<VOUCHER><GUID>x</GUID><DATE>20260401</DATE><VOUCHERTYPENAME>Unknown Type</VOUCHERTYPENAME></VOUCHER>']]);
    }
}
