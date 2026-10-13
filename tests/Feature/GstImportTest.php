<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GstImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class GstImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_gstr1_json_preview_keeps_hsn_summary_as_evidence_only(): void
    {
        [$businessId, $user] = $this->businessUser('PHOOLTAS TRANSRAIL LIMITED', '05AABCP5818M1Z7');
        $file = UploadedFile::fake()->createWithContent('gstr1.json', $this->gstr1Json('05AABCP5818M1Z7'));

        $batch = app(GstImportService::class)->preview($file, 'gstr1_json', $businessId, $user->id);

        $this->assertSame('preview_ready', $batch->status);
        $this->assertSame('05AABCP5818M1Z7', $batch->source_gstin);
        $this->assertSame('082026', $batch->return_period);
        $this->assertDatabaseHas('gst_import_rows', ['gst_import_batch_id' => $batch->id, 'section' => 'gstr1_hsn_summary_evidence', 'hsn_code' => '86040000']);
        $this->assertSame(0, DB::table('sales_vouchers')->count());
        $this->assertSame(0, DB::table('products')->where('name', 'like', '%Railway Or Tramway%')->count());
    }

    public function test_real_client_gstr1_json_parses_hsn_and_document_issue_evidence(): void
    {
        $path = 'C:/Users/Dell/Downloads/05AABCP5818M1Z7_GSTR1_AUG-2026_07102026_131943.json';
        if (!file_exists($path)) {
            $this->markTestSkipped('Client GSTR-1 JSON is not available in this local session.');
        }

        [$businessId, $user] = $this->businessUser('PHOOLTAS TRANSRAIL LIMITED', '05AABCP5818M1Z7');
        $file = new UploadedFile($path, basename($path), 'application/json', null, true);

        $batch = app(GstImportService::class)->preview($file, 'gstr1_json', $businessId, $user->id, '082026');

        $this->assertSame('preview_ready', $batch->status);
        $this->assertSame('05AABCP5818M1Z7', $batch->source_gstin);
        $this->assertSame('082026', $batch->return_period);
        $this->assertSame(176257540.36, round((float) $batch->totals_json['taxable_value'], 2));
        $this->assertSame(31726357.27, round((float) $batch->totals_json['igst_amount'], 2));
        $this->assertSame(4, $batch->summary_json['hsn_rows']);
        $this->assertSame(3, $batch->summary_json['document_issue_rows']);
        $this->assertDatabaseHas('gst_import_rows', ['gst_import_batch_id' => $batch->id, 'section' => 'gstr1_document_issue_evidence']);
        $this->assertSame(0, DB::table('sales_vouchers')->count());
        $this->assertSame(0, DB::table('stock_ledgers')->count());
    }

    public function test_gstr1_json_parses_invoice_notes_and_summaries_without_double_counting_hsn(): void
    {
        [$businessId, $user] = $this->businessUser('PHOOLTAS TRANSRAIL LIMITED', '05AABCP5818M1Z7');
        $payload = [
            'gstin' => '05AABCP5818M1Z7',
            'fp' => '082026',
            'b2b' => [[
                'ctin' => '05ABCDE1234F1Z7',
                'inv' => [[
                    'inum' => 'S-1',
                    'idt' => '01-08-2026',
                    'val' => 1180,
                    'itms' => [['itm_det' => ['txval' => 1000, 'iamt' => 180]]],
                ]],
            ]],
            'cdnr' => [[
                'ctin' => '05ABCDE1234F1Z7',
                'nt' => [[
                    'nt_num' => 'CN-1',
                    'nt_dt' => '02-08-2026',
                    'val' => -118,
                    'itms' => [['itm_det' => ['txval' => -100, 'iamt' => -18]]],
                ]],
            ]],
            'b2cs' => [['txval' => 200, 'camt' => 18, 'samt' => 18]],
            'at' => [['txval' => 50, 'iamt' => 9]],
            'txpd' => [['txval' => -50, 'iamt' => -9]],
            'nil' => ['inv' => [['nil_amt' => 25, 'expt_amt' => 5, 'ngsup_amt' => 0]]],
            'hsn' => ['data' => [['hsn_sc' => '86040000', 'txval' => 1000, 'iamt' => 180]]],
            'doc_issue' => ['doc_det' => [['doc_num' => 1, 'docs' => [['from' => 'S-1', 'to' => 'S-1', 'totnum' => 1, 'cancel' => 0, 'net_issue' => 1]]]]],
        ];

        $file = UploadedFile::fake()->createWithContent('gstr1.json', json_encode($payload));
        $batch = app(GstImportService::class)->preview($file, 'gstr1_json', $businessId, $user->id);

        $this->assertSame('preview_ready', $batch->status);
        $this->assertSame(1130.0, round((float) $batch->totals_json['taxable_value'], 2));
        $this->assertSame(162.0, round((float) $batch->totals_json['igst_amount'], 2));
        $this->assertSame('invoice_and_summary_sections_excluding_hsn_to_avoid_double_counting', $batch->summary_json['totals_basis']);
        $this->assertDatabaseHas('gst_import_rows', ['gst_import_batch_id' => $batch->id, 'section' => 'gstr1_credit_debit_note_evidence', 'document_number' => 'CN-1']);
        $this->assertDatabaseHas('gst_import_rows', ['gst_import_batch_id' => $batch->id, 'section' => 'gstr1_b2cs_summary_evidence']);
        $this->assertDatabaseHas('gst_import_rows', ['gst_import_batch_id' => $batch->id, 'section' => 'gstr1_nil_supply_evidence']);
    }

    public function test_gstr2b_excel_preview_is_supplier_declared_evidence_only(): void
    {
        [$businessId, $user] = $this->businessUser('PHOOLTAS TRANSRAIL LIMITED', '05AABCP5818M1Z7');
        $path = $this->workbook([
            ['GSTIN', '05AABCP5818M1Z7', 'Period', '082026'],
            ['Supplier GSTIN', 'Invoice Number', 'Invoice Date', 'Taxable Value', 'IGST Amount', 'Invoice Value'],
            ['05ABCDE1234F1Z7', 'P-1', '2026-08-05', 1000, 180, 1180],
        ]);

        $file = new UploadedFile($path, 'gstr2b.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $batch = app(GstImportService::class)->preview($file, 'gstr2b_excel', $businessId, $user->id, '082026');

        $this->assertSame('preview_ready', $batch->status);
        $this->assertSame('05AABCP5818M1Z7', $batch->source_gstin);
        $this->assertSame('082026', $batch->return_period);
        $this->assertDatabaseHas('gst_import_rows', ['gst_import_batch_id' => $batch->id, 'section' => 'gstr2b_excel_row_evidence', 'document_number' => 'P-1']);
        $this->assertSame(0, DB::table('purchase_vouchers')->count());
    }

    public function test_invalid_json_is_rejected_without_import_batch(): void
    {
        [$businessId, $user] = $this->businessUser('PHOOLTAS TRANSRAIL LIMITED', '05AABCP5818M1Z7');
        $file = UploadedFile::fake()->createWithContent('broken.json', '{not-json');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(GstImportService::class)->preview($file, 'gstr1_json', $businessId, $user->id);

        $this->assertSame(0, DB::table('gst_import_batches')->count());
    }

    public function test_duplicate_file_upload_is_blocked(): void
    {
        [$businessId, $user] = $this->businessUser('PHOOLTAS TRANSRAIL LIMITED', '05AABCP5818M1Z7');
        $contents = $this->gstr1Json('05AABCP5818M1Z7');

        app(GstImportService::class)->preview(UploadedFile::fake()->createWithContent('gstr1.json', $contents), 'gstr1_json', $businessId, $user->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(GstImportService::class)->preview(UploadedFile::fake()->createWithContent('again.json', $contents), 'gstr1_json', $businessId, $user->id);
    }

    public function test_mismatched_company_gstin_blocks_preview_approval(): void
    {
        [$businessId, $user] = $this->businessUser('ABC Retail Pvt Ltd', '09ABCDE1234F1Z5');
        $file = UploadedFile::fake()->createWithContent('gstr1.json', $this->gstr1Json('05AABCP5818M1Z7'));

        $batch = app(GstImportService::class)->preview($file, 'gstr1_json', $businessId, $user->id);

        $this->assertSame('validation_failed', $batch->status);
        $this->assertContains('File GSTIN does not match the selected company GSTIN.', $batch->validation_json['errors']);
    }

    public function test_company_without_verified_gstin_blocks_gst_file_preview(): void
    {
        [$businessId, $user] = $this->businessUser('ABC Retail Pvt Ltd');
        $file = UploadedFile::fake()->createWithContent('gstr1.json', $this->gstr1Json('05AABCP5818M1Z7'));

        $batch = app(GstImportService::class)->preview($file, 'gstr1_json', $businessId, $user->id);

        $this->assertSame('validation_failed', $batch->status);
        $this->assertContains('Selected company GSTIN is not configured; GST imports are blocked until the company GSTIN is verified.', $batch->validation_json['errors']);
    }

    public function test_expected_return_period_mismatch_blocks_preview_approval(): void
    {
        [$businessId, $user] = $this->businessUser('PHOOLTAS TRANSRAIL LIMITED', '05AABCP5818M1Z7');
        $file = UploadedFile::fake()->createWithContent('gstr1.json', $this->gstr1Json('05AABCP5818M1Z7'));

        $batch = app(GstImportService::class)->preview($file, 'gstr1_json', $businessId, $user->id, '072026');

        $this->assertSame('validation_failed', $batch->status);
        $this->assertContains('File return period does not match the expected filing period.', $batch->validation_json['errors']);
    }

    public function test_invalid_source_gstin_blocks_preview_approval(): void
    {
        [$businessId, $user] = $this->businessUser('PHOOLTAS TRANSRAIL LIMITED', '05AABCP5818M1Z7');
        $file = UploadedFile::fake()->createWithContent('gstr1.json', $this->gstr1Json('05INVALIDGSTIN'));

        $batch = app(GstImportService::class)->preview($file, 'gstr1_json', $businessId, $user->id);

        $this->assertSame('validation_failed', $batch->status);
        $this->assertContains('Source GSTIN is present but invalid.', $batch->validation_json['errors']);
    }

    public function test_import_workspace_screen_is_company_scoped(): void
    {
        [$businessId, $user] = $this->businessUser('ABC Retail Pvt Ltd', '09ABCDE1234F1Z5');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/accounting/gst-imports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('GST/ImportWorkspace')
                ->where('company.id', $businessId)
                ->where('company.gstin', '09ABCDE1234F1Z5')
                ->has('sourceTypes', 4)
                ->etc());
    }

    public function test_phooltas_onboarding_screen_is_draft_only(): void
    {
        [$businessId, $user] = $this->businessUser('ABC Retail Pvt Ltd');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/setup/companies/onboarding')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Setup/CompanyOnboarding')
                ->where('draft.legal_name', 'PHOOLTAS TRANSRAIL LIMITED')
                ->where('draft.gstin', '05AABCP5818M1Z7')
                ->where('verification.can_create', false)
                ->etc());

        $this->assertDatabaseMissing('companies', ['gstin' => '05AABCP5818M1Z7']);
    }

    private function businessUser(string $name, ?string $gstin = null): array
    {
        $businessId = DB::table('companies')->insertGetId([
            'name' => $name,
            'gstin' => $gstin,
            'state' => 'Uttarakhand',
            'financial_year' => '2026-27',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'tenant_id' => $businessId,
            'role_id' => 2,
            'is_active' => 1,
            'status' => 'active',
        ]);

        DB::table('company_user_access')->insert([
            'company_id' => $businessId,
            'user_id' => $user->id,
            'access_role' => 'admin',
            'is_default' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$businessId, $user];
    }

    private function gstr1Json(string $gstin): string
    {
        return json_encode([
            'gstin' => $gstin,
            'fp' => '082026',
            'hsn' => [
                'hsn_b2b' => [
                    [
                        'num' => 1,
                        'hsn_sc' => '86040000',
                        'txval' => 80071728.47,
                        'iamt' => 14412911.12,
                        'camt' => 0,
                        'samt' => 0,
                        'csamt' => 0,
                        'user_desc' => 'Railway Or Tramway Maintenance Or Service Vehicles',
                        'uqc' => 'NOS',
                        'qty' => 11,
                        'rt' => 18,
                    ],
                ],
            ],
        ]);
    }

    private function workbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = storage_path('framework/testing/gst-import-' . uniqid('', true) . '.xlsx');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
