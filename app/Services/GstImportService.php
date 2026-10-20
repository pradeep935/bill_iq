<?php

namespace App\Services;

use App\Models\GstImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class GstImportService
{
    public const TYPES = ['gstr1_json', 'gstr2b_excel', 'gst_return_summary_excel', 'transaction_excel'];

    public function preview(UploadedFile $file, string $sourceType, int $businessId, int $userId, ?string $expectedReturnPeriod = null): GstImportBatch
    {
        if (!in_array($sourceType, self::TYPES, true)) {
            throw ValidationException::withMessages(['source_type' => 'Unsupported GST import source type.']);
        }

        $hash = hash_file('sha256', $file->getRealPath());
        $existing = GstImportBatch::query()
            ->where('business_id', $businessId)
            ->where('file_hash', $hash)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages(['file' => 'This exact file has already been uploaded for the selected company.']);
        }

        $parsed = $this->parse($file, $sourceType);
        $company = Schema::hasTable('companies') ? DB::table('companies')->where('id', $businessId)->first() : null;
        $validation = $this->validateParsed($parsed, $sourceType, $company, $expectedReturnPeriod);
        $path = $file->storeAs(
            'gst-imports/' . $businessId . '/' . now()->format('Y/m'),
            $hash . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $file->getClientOriginalName()),
            'local'
        );

        return DB::transaction(function () use ($businessId, $userId, $sourceType, $file, $hash, $parsed, $validation, $path, $expectedReturnPeriod) {
            $batch = GstImportBatch::query()->create([
                'business_id' => $businessId,
                'company_id' => $businessId,
                'source_type' => $sourceType,
                'status' => $validation['errors'] ? 'validation_failed' : 'preview_ready',
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'file_hash' => $hash,
                'source_gstin' => $parsed['source_gstin'],
                'return_period' => $parsed['return_period'],
                'detected_legal_name' => $parsed['legal_name'],
                'totals_json' => $parsed['totals'],
                'validation_json' => $validation,
                'summary_json' => array_merge($parsed['summary'], [
                    'expected_return_period' => $this->period($expectedReturnPeriod),
                ]),
                'uploaded_by' => $userId,
            ]);

            foreach ($parsed['rows'] as $row) {
                $batch->rows()->create($row);
            }

            foreach ($this->reconciliationItems($batch, $parsed, $validation) as $item) {
                $batch->reconciliationItems()->create($item);
            }

            return $batch->fresh(['rows', 'reconciliationItems']);
        });
    }

    public function approve(GstImportBatch $batch, int $userId): GstImportBatch
    {
        if ($batch->status === 'validation_failed') {
            throw ValidationException::withMessages(['batch' => 'Resolve validation errors before approval.']);
        }

        $batch->forceFill([
            'status' => 'approved_for_reconciliation',
            'approved_by' => $userId,
            'approved_at' => now(),
        ])->save();

        return $batch->fresh(['rows', 'reconciliationItems']);
    }

    public function commit(GstImportBatch $batch): GstImportBatch
    {
        if ($batch->status !== 'approved_for_reconciliation') {
            throw ValidationException::withMessages(['batch' => 'Approve this import before marking it committed.']);
        }

        $batch->forceFill([
            'status' => 'committed_as_evidence',
            'committed_at' => now(),
        ])->save();

        return $batch->fresh(['rows', 'reconciliationItems']);
    }

    public function parse(UploadedFile $file, string $sourceType): array
    {
        if ($sourceType === 'gstr1_json') {
            return $this->parseGstr1Json((string) file_get_contents($file->getRealPath()));
        }

        return $this->parseExcel($file, $sourceType);
    }

    private function parseGstr1Json(string $contents): array
    {
        $data = json_decode($contents, true);
        if (!is_array($data)) {
            throw ValidationException::withMessages(['file' => 'Invalid JSON file.']);
        }

        $rawSourceGstin = $data['gstin'] ?? $data['gstin_no'] ?? $data['fp_gstin'] ?? null;
        $sourceGstin = $this->normalizeGstin($rawSourceGstin);
        $period = $this->period($data['fp'] ?? $data['ret_period'] ?? null);
        $rows = [];
        $primaryTotals = $this->emptyTotals();
        $summaryTotals = $this->emptyTotals();
        $documentTotals = $this->emptyTotals();
        $sectionTotals = [];
        $rowNumber = 1;
        $primaryRows = 0;

        foreach (($data['b2b'] ?? []) as $party) {
            foreach (($party['inv'] ?? []) as $invoice) {
                $amounts = $this->invoiceAmounts($invoice);
                $rows[] = $this->row($rowNumber++, 'gstr1_b2b_invoice_evidence', [
                    'counterparty_gstin' => $this->normalizeGstin($party['ctin'] ?? null),
                    'document_number' => $invoice['inum'] ?? null,
                    'document_date' => $this->date($invoice['idt'] ?? null),
                    'taxable_value' => $amounts['taxable_value'],
                    'cgst_amount' => $amounts['cgst_amount'],
                    'sgst_amount' => $amounts['sgst_amount'],
                    'igst_amount' => $amounts['igst_amount'],
                    'cess_amount' => $amounts['cess_amount'],
                    'total_value' => $amounts['total_value'],
                    'messages_json' => ['Invoice-level statutory evidence only; no sales invoice is posted automatically.'],
                    'payload_json' => $invoice,
                ]);
                $this->addAmountTotals($primaryTotals, $amounts);
                $this->addSectionTotals($sectionTotals, 'gstr1_b2b_invoice_evidence', $amounts);
                $primaryRows++;
            }
        }

        foreach (($data['b2cl'] ?? []) as $state) {
            foreach (($state['inv'] ?? []) as $invoice) {
                $amounts = $this->invoiceAmounts($invoice);
                $rows[] = $this->row($rowNumber++, 'gstr1_b2cl_invoice_evidence', [
                    'document_number' => $invoice['inum'] ?? null,
                    'document_date' => $this->date($invoice['idt'] ?? null),
                    'taxable_value' => $amounts['taxable_value'],
                    'cgst_amount' => $amounts['cgst_amount'],
                    'sgst_amount' => $amounts['sgst_amount'],
                    'igst_amount' => $amounts['igst_amount'],
                    'cess_amount' => $amounts['cess_amount'],
                    'total_value' => $amounts['total_value'],
                    'messages_json' => ['Large B2C invoice evidence only; no customer or sales invoice is posted automatically.'],
                    'payload_json' => ['state' => $state['pos'] ?? null, 'invoice' => $invoice],
                ]);
                $this->addAmountTotals($primaryTotals, $amounts);
                $this->addSectionTotals($sectionTotals, 'gstr1_b2cl_invoice_evidence', $amounts);
                $primaryRows++;
            }
        }

        foreach (($data['exp'] ?? []) as $export) {
            foreach (($export['inv'] ?? []) as $invoice) {
                $amounts = $this->invoiceAmounts($invoice);
                $rows[] = $this->row($rowNumber++, 'gstr1_export_invoice_evidence', [
                    'document_number' => $invoice['inum'] ?? null,
                    'document_date' => $this->date($invoice['idt'] ?? null),
                    'taxable_value' => $amounts['taxable_value'],
                    'cgst_amount' => $amounts['cgst_amount'],
                    'sgst_amount' => $amounts['sgst_amount'],
                    'igst_amount' => $amounts['igst_amount'],
                    'cess_amount' => $amounts['cess_amount'],
                    'total_value' => $amounts['total_value'],
                    'messages_json' => ['Export invoice evidence only; no sales invoice is posted automatically.'],
                    'payload_json' => ['export_type' => $export['exp_typ'] ?? null, 'invoice' => $invoice],
                ]);
                $this->addAmountTotals($primaryTotals, $amounts);
                $this->addSectionTotals($sectionTotals, 'gstr1_export_invoice_evidence', $amounts);
                $primaryRows++;
            }
        }

        foreach (($data['cdnr'] ?? []) as $party) {
            foreach (($party['nt'] ?? []) as $note) {
                $amounts = $this->invoiceAmounts($note);
                $rows[] = $this->row($rowNumber++, 'gstr1_credit_debit_note_evidence', [
                    'counterparty_gstin' => $this->normalizeGstin($party['ctin'] ?? null),
                    'document_number' => $note['nt_num'] ?? null,
                    'document_date' => $this->date($note['nt_dt'] ?? null),
                    'taxable_value' => $amounts['taxable_value'],
                    'cgst_amount' => $amounts['cgst_amount'],
                    'sgst_amount' => $amounts['sgst_amount'],
                    'igst_amount' => $amounts['igst_amount'],
                    'cess_amount' => $amounts['cess_amount'],
                    'total_value' => $amounts['total_value'],
                    'messages_json' => ['Credit/debit note evidence only; no voucher is posted automatically.'],
                    'payload_json' => $note,
                ]);
                $this->addAmountTotals($primaryTotals, $amounts);
                $this->addSectionTotals($sectionTotals, 'gstr1_credit_debit_note_evidence', $amounts);
                $primaryRows++;
            }
        }

        foreach (($data['cdnur'] ?? []) as $note) {
            $amounts = $this->invoiceAmounts($note);
            $rows[] = $this->row($rowNumber++, 'gstr1_unregistered_credit_debit_note_evidence', [
                'document_number' => $note['nt_num'] ?? null,
                'document_date' => $this->date($note['nt_dt'] ?? null),
                'taxable_value' => $amounts['taxable_value'],
                'cgst_amount' => $amounts['cgst_amount'],
                'sgst_amount' => $amounts['sgst_amount'],
                'igst_amount' => $amounts['igst_amount'],
                'cess_amount' => $amounts['cess_amount'],
                'total_value' => $amounts['total_value'],
                'messages_json' => ['Unregistered credit/debit note evidence only; no voucher is posted automatically.'],
                'payload_json' => $note,
            ]);
            $this->addAmountTotals($primaryTotals, $amounts);
            $this->addSectionTotals($sectionTotals, 'gstr1_unregistered_credit_debit_note_evidence', $amounts);
            $primaryRows++;
        }

        foreach (['b2cs' => 'gstr1_b2cs_summary_evidence', 'at' => 'gstr1_advance_tax_evidence', 'txpd' => 'gstr1_advance_adjustment_evidence'] as $key => $section) {
            foreach (($data[$key] ?? []) as $summary) {
                $amounts = $this->summaryAmounts($summary);
                $rows[] = $this->row($rowNumber++, $section, [
                    'taxable_value' => $amounts['taxable_value'],
                    'cgst_amount' => $amounts['cgst_amount'],
                    'sgst_amount' => $amounts['sgst_amount'],
                    'igst_amount' => $amounts['igst_amount'],
                    'cess_amount' => $amounts['cess_amount'],
                    'total_value' => $amounts['total_value'],
                    'messages_json' => ['GSTR-1 aggregate evidence only; no invoice is fabricated from summary data.'],
                    'payload_json' => $summary,
                ]);
                $this->addAmountTotals($primaryTotals, $amounts);
                $this->addSectionTotals($sectionTotals, $section, $amounts);
                $primaryRows++;
            }
        }

        foreach (($data['nil']['inv'] ?? []) as $nil) {
            $amounts = [
                'taxable_value' => (float) ($nil['nil_amt'] ?? 0) + (float) ($nil['expt_amt'] ?? 0) + (float) ($nil['ngsup_amt'] ?? 0),
                'cgst_amount' => 0.0,
                'sgst_amount' => 0.0,
                'igst_amount' => 0.0,
                'cess_amount' => 0.0,
                'total_value' => (float) ($nil['nil_amt'] ?? 0) + (float) ($nil['expt_amt'] ?? 0) + (float) ($nil['ngsup_amt'] ?? 0),
            ];
            $rows[] = $this->row($rowNumber++, 'gstr1_nil_supply_evidence', [
                'taxable_value' => $amounts['taxable_value'],
                'total_value' => $amounts['total_value'],
                'messages_json' => ['Nil/exempt/non-GST outward supply evidence only.'],
                'payload_json' => $nil,
            ]);
            $this->addAmountTotals($primaryTotals, $amounts);
            $this->addSectionTotals($sectionTotals, 'gstr1_nil_supply_evidence', $amounts);
            $primaryRows++;
        }

        foreach (($data['hsn']['data'] ?? $data['hsn']['det'] ?? $data['hsn']['hsn_b2b'] ?? []) as $hsn) {
            $amounts = $this->summaryAmounts($hsn);
            $rows[] = $this->row($rowNumber++, 'gstr1_hsn_summary_evidence', [
                'hsn_code' => $hsn['hsn_sc'] ?? $hsn['hsn'] ?? null,
                'taxable_value' => $amounts['taxable_value'],
                'cgst_amount' => $amounts['cgst_amount'],
                'sgst_amount' => $amounts['sgst_amount'],
                'igst_amount' => $amounts['igst_amount'],
                'cess_amount' => $amounts['cess_amount'],
                'total_value' => $amounts['total_value'],
                'messages_json' => ['HSN summary retained as reconciliation evidence only; no products or invoices are created.'],
                'payload_json' => $hsn,
            ]);
            $this->addAmountTotals($summaryTotals, $amounts);
            $this->addSectionTotals($sectionTotals, 'gstr1_hsn_summary_evidence', $amounts);
        }

        foreach (($data['doc_issue']['doc_det'] ?? []) as $group) {
            foreach (($group['docs'] ?? []) as $doc) {
                $rows[] = $this->row($rowNumber++, 'gstr1_document_issue_evidence', [
                    'document_number' => trim(($doc['from'] ?? '') . ' to ' . ($doc['to'] ?? '')) ?: null,
                    'messages_json' => ['Document issue register evidence only; no accounting document is posted.'],
                    'payload_json' => ['doc_num' => $group['doc_num'] ?? null, 'document' => $doc],
                ]);
                $this->addSectionTotals($sectionTotals, 'gstr1_document_issue_evidence', $this->emptyTotals());
            }
        }

        $totals = $primaryRows > 0 ? $primaryTotals : $summaryTotals;

        return $this->parsed($sourceGstin, $period, null, $rows, $totals, [
            'raw_source_gstin' => is_scalar($rawSourceGstin) ? strtoupper(trim((string) $rawSourceGstin)) : null,
            'b2b_count' => count($data['b2b'] ?? []),
            'hsn_rows' => collect($rows)->where('section', 'gstr1_hsn_summary_evidence')->count(),
            'invoice_like_rows' => $primaryRows,
            'document_issue_rows' => collect($rows)->where('section', 'gstr1_document_issue_evidence')->count(),
            'section_totals' => $sectionTotals,
            'primary_totals' => $primaryTotals,
            'hsn_summary_totals' => $summaryTotals,
            'document_issue_totals' => $documentTotals,
            'totals_basis' => $primaryRows > 0 ? 'invoice_and_summary_sections_excluding_hsn_to_avoid_double_counting' : 'hsn_summary_only_no_invoice_level_rows_present',
            'posting_policy' => 'GST return evidence only. No sales invoices or products are posted from this import.',
        ]);
    }

    private function invoiceAmounts(array $document): array
    {
        $amounts = $this->emptyTotals();

        foreach (($document['itms'] ?? []) as $item) {
            $this->addAmountTotals($amounts, $this->summaryAmounts($item['itm_det'] ?? $item));
        }

        if (empty($document['itms'])) {
            $amounts = $this->summaryAmounts($document);
        }

        if (!empty($document['val'])) {
            $amounts['total_value'] = (float) $document['val'];
        } elseif ($amounts['total_value'] == 0.0) {
            $amounts['total_value'] = $amounts['taxable_value'] + $amounts['cgst_amount'] + $amounts['sgst_amount'] + $amounts['igst_amount'] + $amounts['cess_amount'];
        }

        return $amounts;
    }

    private function summaryAmounts(array $payload): array
    {
        $taxable = (float) ($payload['txval'] ?? $payload['taxable_value'] ?? 0);
        $cgst = (float) ($payload['camt'] ?? $payload['cgst'] ?? 0);
        $sgst = (float) ($payload['samt'] ?? $payload['sgst'] ?? 0);
        $igst = (float) ($payload['iamt'] ?? $payload['igst'] ?? 0);
        $cess = (float) ($payload['csamt'] ?? $payload['cess'] ?? 0);
        $total = (float) ($payload['val'] ?? $payload['total_value'] ?? 0);

        return [
            'taxable_value' => $taxable,
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'igst_amount' => $igst,
            'cess_amount' => $cess,
            'total_value' => $total ?: $taxable + $cgst + $sgst + $igst + $cess,
        ];
    }

    private function parseExcel(UploadedFile $file, string $sourceType): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => 'Unable to read Excel workbook.']);
        }

        $rows = [];
        $totals = $this->emptyTotals();
        $sourceGstin = null;
        $rawSourceGstin = null;
        $period = null;
        $rowNumber = 1;

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $highestRow = min($sheet->getHighestDataRow(), 250);
            $highestColumn = $sheet->getHighestDataColumn();
            $headers = [];

            for ($r = 1; $r <= $highestRow; $r++) {
                $values = $sheet->rangeToArray('A' . $r . ':' . $highestColumn . $r, null, true, false)[0] ?? [];
                foreach ($values as $value) {
                    if (!$sourceGstin && preg_match('/\b[0-9A-Z]{15}\b/', strtoupper((string) $value), $rawMatch)) {
                        $rawSourceGstin = $rawMatch[0];
                    }
                    if (!$sourceGstin && preg_match('/\b[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]\b/', strtoupper((string) $value), $match)) {
                        $sourceGstin = $match[0];
                    }
                    if (!$period && preg_match('/\b(0[1-9]|1[0-2])[\/-]?(20\d{2})\b/', (string) $value, $match)) {
                        $period = $match[1] . $match[2];
                    }
                }

                $candidateHeaders = array_map(fn ($value) => strtolower(trim((string) $value)), $values);
                if (!$headers && $this->looksLikeExcelHeader($candidateHeaders)) {
                    $headers = $candidateHeaders;
                    continue;
                }

                if (!$headers || !array_filter($values, fn ($value) => trim((string) $value) !== '')) {
                    continue;
                }

                $payload = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $payload[$header] = $values[$index] ?? null;
                    }
                }

                $taxable = $this->amount($payload, ['taxable value', 'taxable', 'txval']);
                $cgst = $this->amount($payload, ['cgst', 'cgst amount']);
                $sgst = $this->amount($payload, ['sgst', 'sgst amount']);
                $igst = $this->amount($payload, ['igst', 'igst amount']);
                $cess = $this->amount($payload, ['cess', 'cess amount']);
                $total = $this->amount($payload, ['invoice value', 'total value', 'total']);

                $rows[] = $this->row($rowNumber++, $sourceType . '_row_evidence', [
                    'counterparty_gstin' => $this->normalizeGstin($payload['gstin'] ?? $payload['supplier gstin'] ?? $payload['ctin'] ?? null),
                    'document_number' => $payload['invoice number'] ?? $payload['document number'] ?? $payload['inum'] ?? null,
                    'document_date' => $this->date($payload['invoice date'] ?? $payload['document date'] ?? null),
                    'hsn_code' => $payload['hsn'] ?? $payload['hsn/sac'] ?? null,
                    'taxable_value' => $taxable,
                    'cgst_amount' => $cgst,
                    'sgst_amount' => $sgst,
                    'igst_amount' => $igst,
                    'cess_amount' => $cess,
                    'total_value' => $total ?: $taxable + $cgst + $sgst + $igst + $cess,
                    'messages_json' => [$sourceType === 'gstr2b_excel' ? 'Supplier-declared ITC evidence only; no purchase voucher is posted.' : 'Preview evidence only; no business transaction is posted.'],
                    'payload_json' => ['sheet' => $sheet->getTitle(), 'row' => $payload],
                ]);
                $this->addTotals($totals, $taxable, $cgst, $sgst, $igst, $cess, $total ?: $taxable + $cgst + $sgst + $igst + $cess);
            }
        }

        return $this->parsed($sourceGstin, $period, null, $rows, $totals, [
            'raw_source_gstin' => $rawSourceGstin,
            'row_count' => count($rows),
            'posting_policy' => $sourceType === 'gstr2b_excel'
                ? 'GSTR-2B is supplier-declared ITC evidence only. No purchase vouchers are posted automatically.'
                : 'Workbook is preview evidence only until an approved import mapping exists.',
        ]);
    }

    private function validateParsed(array $parsed, string $sourceType, ?object $company, ?string $expectedReturnPeriod = null): array
    {
        $errors = [];
        $warnings = [];
        $companyGstin = $this->normalizeGstin($company->gstin ?? null);
        $expectedReturnPeriod = $this->period($expectedReturnPeriod);

        if (!$parsed['source_gstin'] && !empty($parsed['summary']['raw_source_gstin'])) {
            $errors[] = 'Source GSTIN is present but invalid.';
        } elseif (!$parsed['source_gstin']) {
            $warnings[] = 'Source GSTIN was not detected from the file.';
        } elseif (!$companyGstin) {
            $errors[] = 'Selected company GSTIN is not configured; GST imports are blocked until the company GSTIN is verified.';
        } elseif ($companyGstin && $parsed['source_gstin'] !== $companyGstin) {
            $errors[] = 'File GSTIN does not match the selected company GSTIN.';
        }

        if (!$parsed['return_period']) {
            $warnings[] = 'Return period was not detected from the file.';
        } elseif ($expectedReturnPeriod && $parsed['return_period'] !== $expectedReturnPeriod) {
            $errors[] = 'File return period does not match the expected filing period.';
        }

        if (!count($parsed['rows'])) {
            $errors[] = 'No previewable GST rows were detected.';
        }

        if ($sourceType === 'gstr1_json') {
            $warnings[] = 'GSTR-1 HSN summaries are reconciliation evidence only and will not create products or invoices.';
        }

        if ($sourceType === 'gstr2b_excel') {
            $warnings[] = 'GSTR-2B remains supplier-declared ITC evidence and will not create purchase vouchers.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function reconciliationItems(GstImportBatch $batch, array $parsed, array $validation): array
    {
        return [
            [
                'category' => 'file_validation',
                'status' => $validation['errors'] ? 'blocked' : 'ready',
                'details_json' => $validation,
            ],
            [
                'category' => 'gst_totals',
                'status' => 'open',
                'expected_amount' => 0,
                'actual_amount' => $parsed['totals']['total_value'],
                'difference_amount' => $parsed['totals']['total_value'],
                'details_json' => ['source_type' => $batch->source_type, 'totals' => $parsed['totals']],
            ],
        ];
    }

    private function parsed(?string $gstin, ?string $period, ?string $legalName, array $rows, array $totals, array $summary): array
    {
        return [
            'source_gstin' => $gstin,
            'return_period' => $period,
            'legal_name' => $legalName,
            'rows' => $rows,
            'totals' => $totals,
            'summary' => $summary,
        ];
    }

    private function row(int $number, string $section, array $data): array
    {
        $duplicateKey = implode('|', array_filter([
            $section,
            $data['counterparty_gstin'] ?? null,
            $data['document_number'] ?? null,
            $data['document_date'] ?? null,
            $data['hsn_code'] ?? null,
        ]));

        return array_merge([
            'row_number' => $number,
            'section' => $section,
            'source_key' => $duplicateKey ?: null,
            'counterparty_gstin' => null,
            'document_number' => null,
            'document_date' => null,
            'hsn_code' => null,
            'taxable_value' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'cess_amount' => 0,
            'total_value' => 0,
            'severity' => 'info',
            'messages_json' => [],
            'payload_json' => [],
            'duplicate_key' => $duplicateKey ?: null,
        ], $data);
    }

    private function emptyTotals(): array
    {
        return ['taxable_value' => 0, 'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0, 'cess_amount' => 0, 'total_value' => 0];
    }

    private function addTotals(array &$totals, float $taxable, float $cgst, float $sgst, float $igst, float $cess, float $total): void
    {
        $totals['taxable_value'] += $taxable;
        $totals['cgst_amount'] += $cgst;
        $totals['sgst_amount'] += $sgst;
        $totals['igst_amount'] += $igst;
        $totals['cess_amount'] += $cess;
        $totals['total_value'] += $total;
    }

    private function addAmountTotals(array &$totals, array $amounts): void
    {
        $this->addTotals(
            $totals,
            (float) ($amounts['taxable_value'] ?? 0),
            (float) ($amounts['cgst_amount'] ?? 0),
            (float) ($amounts['sgst_amount'] ?? 0),
            (float) ($amounts['igst_amount'] ?? 0),
            (float) ($amounts['cess_amount'] ?? 0),
            (float) ($amounts['total_value'] ?? 0)
        );
    }

    private function addSectionTotals(array &$sectionTotals, string $section, array $amounts): void
    {
        if (!array_key_exists($section, $sectionTotals)) {
            $sectionTotals[$section] = $this->emptyTotals();
            $sectionTotals[$section]['rows'] = 0;
        }

        $sectionTotals[$section]['rows']++;
        $this->addAmountTotals($sectionTotals[$section], $amounts);
    }

    private function normalizeGstin($value): ?string
    {
        $value = strtoupper(trim((string) $value));
        return preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $value) ? $value : null;
    }

    private function period($value): ?string
    {
        $value = preg_replace('/[^0-9]/', '', (string) $value);
        return strlen($value) === 6 ? $value : null;
    }

    private function date($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function looksLikeExcelHeader(array $headers): bool
    {
        $joined = implode(' ', $headers);
        $hasDocument = str_contains($joined, 'invoice') || str_contains($joined, 'document') || str_contains($joined, 'note');
        $hasAmount = str_contains($joined, 'taxable') || str_contains($joined, 'igst') || str_contains($joined, 'cgst') || str_contains($joined, 'sgst');
        $hasParty = str_contains($joined, 'gstin') || str_contains($joined, 'supplier') || str_contains($joined, 'recipient');

        return $hasParty && ($hasDocument || $hasAmount);
    }

    private function amount(array $payload, array $keys): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return (float) str_replace(',', '', (string) $payload[$key]);
            }
        }

        return 0.0;
    }
}
