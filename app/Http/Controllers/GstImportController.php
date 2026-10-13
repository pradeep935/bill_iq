<?php

namespace App\Http\Controllers;

use App\Models\GstImportBatch;
use App\Services\GstImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class GstImportController extends Controller
{
    public function index()
    {
        if ($redirect = AppController::guardPage('gst')) {
            return $redirect;
        }

        $businessId = AppController::businessId();
        $company = Schema::hasTable('companies') ? DB::table('companies')->where('id', $businessId)->first() : null;

        return Inertia::render('GST/ImportWorkspace', [
            'page' => 'gst-imports',
            'title' => 'GST Import Workspace',
            'company' => [
                'id' => $businessId,
                'name' => $company->name ?? 'Current Company',
                'gstin' => $company->gstin ?? null,
                'state' => $company->state ?? null,
            ],
            'sourceTypes' => [
                ['key' => 'gstr1_json', 'label' => 'GSTR-1 JSON'],
                ['key' => 'gstr2b_excel', 'label' => 'GSTR-2B Excel'],
                ['key' => 'gst_return_summary_excel', 'label' => 'GST Return Summary Excel'],
                ['key' => 'transaction_excel', 'label' => 'Future Transaction CSV/XLSX'],
            ],
            'batches' => $this->batches($businessId),
        ]);
    }

    public function store(Request $request, GstImportService $imports)
    {
        if ($redirect = AppController::guardPage('gst')) {
            return $redirect;
        }

        $data = $request->validate([
            'source_type' => ['required', Rule::in(GstImportService::TYPES)],
            'file' => ['required', 'file', 'max:20480', 'mimes:json,xlsx,xls,csv,txt'],
            'expected_return_period' => ['nullable', 'regex:/^(0[1-9]|1[0-2])20[0-9]{2}$/'],
        ]);

        $batch = $imports->preview(
            $data['file'],
            $data['source_type'],
            AppController::businessId(),
            (int) $request->user()->id,
            $data['expected_return_period'] ?? null
        );

        return response()->json([
            'message' => 'File parsed for preview. No business transactions were posted.',
            'batch' => $this->batchPayload($batch),
            'batches' => $this->batches(AppController::businessId()),
        ], 201);
    }

    public function show(GstImportBatch $batch)
    {
        $this->authorizeBatch($batch);

        return response()->json($this->batchPayload($batch->load(['rows', 'reconciliationItems'])));
    }

    public function approve(Request $request, GstImportBatch $batch, GstImportService $imports)
    {
        $this->authorizeBatch($batch);
        abort_unless($request->user() && ($request->user()->isSuperAdmin() || $request->user()->isAdmin()), 403);

        return response()->json([
            'message' => 'Import approved for reconciliation evidence. No sales, purchases or ledgers were posted.',
            'batch' => $this->batchPayload($imports->approve($batch, (int) $request->user()->id)),
            'batches' => $this->batches(AppController::businessId()),
        ]);
    }

    public function commit(GstImportBatch $batch, GstImportService $imports)
    {
        $this->authorizeBatch($batch);

        return response()->json([
            'message' => 'Import committed as GST evidence only. Business transactions remain unchanged.',
            'batch' => $this->batchPayload($imports->commit($batch)),
            'batches' => $this->batches(AppController::businessId()),
        ]);
    }

    private function authorizeBatch(GstImportBatch $batch): void
    {
        abort_unless((int) $batch->business_id === AppController::businessId(), 404);
    }

    private function batches(int $businessId)
    {
        if (!Schema::hasTable('gst_import_batches')) {
            return [];
        }

        return GstImportBatch::query()
            ->where('business_id', $businessId)
            ->withCount('rows')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (GstImportBatch $batch) => $this->batchSummary($batch))
            ->values();
    }

    private function batchPayload(GstImportBatch $batch): array
    {
        $batch->loadMissing(['rows', 'reconciliationItems']);

        return array_merge($this->batchSummary($batch), [
            'validation' => $batch->validation_json ?: ['errors' => [], 'warnings' => []],
            'summary' => $batch->summary_json ?: [],
            'totals' => $batch->totals_json ?: [],
            'rows' => $batch->rows->take(100)->map(fn ($row) => [
                'id' => $row->id,
                'row_number' => $row->row_number,
                'section' => $row->section,
                'counterparty_gstin' => $row->counterparty_gstin,
                'document_number' => $row->document_number,
                'document_date' => optional($row->document_date)->toDateString(),
                'hsn_code' => $row->hsn_code,
                'taxable_value' => (float) $row->taxable_value,
                'cgst_amount' => (float) $row->cgst_amount,
                'sgst_amount' => (float) $row->sgst_amount,
                'igst_amount' => (float) $row->igst_amount,
                'cess_amount' => (float) $row->cess_amount,
                'total_value' => (float) $row->total_value,
                'severity' => $row->severity,
                'messages' => $row->messages_json ?: [],
            ])->values(),
            'reconciliation' => $batch->reconciliationItems->map(fn ($item) => [
                'category' => $item->category,
                'status' => $item->status,
                'expected_amount' => (float) $item->expected_amount,
                'actual_amount' => (float) $item->actual_amount,
                'difference_amount' => (float) $item->difference_amount,
                'details' => $item->details_json ?: [],
            ])->values(),
        ]);
    }

    private function batchSummary(GstImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'source_type' => $batch->source_type,
            'status' => $batch->status,
            'original_filename' => $batch->original_filename,
            'source_gstin' => $batch->source_gstin,
            'return_period' => $batch->return_period,
            'detected_legal_name' => $batch->detected_legal_name,
            'rows_count' => $batch->rows_count ?? $batch->rows()->count(),
            'created_at' => optional($batch->created_at)->toDateTimeString(),
        ];
    }
}
