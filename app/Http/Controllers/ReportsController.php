<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancialReportSnapshotRequest;
use App\Http\Requests\FinancialYearClosureRequest;
use App\Http\Requests\FinancialYearReopenRequest;
use App\Http\Requests\AccountClassificationRequest;
use App\Services\FinancialReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportsController extends Controller
{
    private FinancialReportService $financialReports;

    public function __construct(FinancialReportService $financialReports)
    {
        $this->financialReports = $financialReports;
    }

    public function business()
    {
        if ($redirect = AppController::guardPage('reports')) return $redirect;
        return Inertia::render('Reports/Financial', ['page' => 'reports', 'title' => 'Financial Reports']);
    }

    public function inventory()
    {
        return ModuleController::render('inventory-reports', 'Inventory Reports');
    }

    public function stockLedger()
    {
        return ModuleController::render('stock-ledger', 'Stock Ledger');
    }

    public function stockValuation()
    {
        return ModuleController::render('inventory-valuation', 'Stock Valuation');
    }

    public function auditTrail()
    {
        return ModuleController::render('inventory-audit-trail', 'Voucher Audit Trail');
    }

    public function acceptance()
    {
        return ModuleController::render('acceptance', 'Acceptance Matrix');
    }

    public function financialReferences()
    {
        return response()->json($this->financialReports->references());
    }

    public function saveClassification(AccountClassificationRequest $request)
    {
        return response()->json(['message' => 'Account classification saved.', 'classification' => $this->financialReports->saveClassification($request->validated())]);
    }

    public function financialDashboard(Request $request)
    {
        return response()->json($this->financialReports->dashboard($request->all()));
    }

    public function dayBook(Request $request)
    {
        return response()->json($this->financialReports->getDayBook($request->all()));
    }

    public function journalRegister(Request $request)
    {
        return response()->json($this->financialReports->getJournalRegister($request->all()));
    }

    public function ledger(Request $request)
    {
        return response()->json($this->financialReports->getLedgerReport($request->all()));
    }

    public function trialBalance(Request $request)
    {
        return response()->json($this->financialReports->getTrialBalance($request->all()));
    }

    public function profitAndLoss(Request $request)
    {
        return response()->json($this->financialReports->getProfitAndLoss($request->all()));
    }

    public function balanceSheet(Request $request)
    {
        return response()->json($this->financialReports->getBalanceSheet($request->all()));
    }

    public function cashFlow(Request $request)
    {
        return response()->json($this->financialReports->getCashFlow($request->all()));
    }

    public function receivables(Request $request)
    {
        return response()->json($this->financialReports->getReceivableSummary($request->all()));
    }

    public function payables(Request $request)
    {
        return response()->json($this->financialReports->getPayableSummary($request->all()));
    }

    public function comparative(Request $request)
    {
        return response()->json($this->financialReports->getComparativeReport($request->all()));
    }

    public function branchFinancials(Request $request)
    {
        return response()->json($this->financialReports->getBranchFinancials($request->all()));
    }

    public function schedule(Request $request)
    {
        return response()->json($this->financialReports->getAccountSchedule($request->all()));
    }

    public function ratios(Request $request)
    {
        return response()->json($this->financialReports->ratios($request->all()));
    }

    public function exceptions(Request $request)
    {
        return response()->json($this->financialReports->exceptionReport($request->all()));
    }

    public function closingChecklist(FinancialYearClosureRequest $request)
    {
        return response()->json($this->financialReports->closingChecklist($request->validated()));
    }

    public function closeYear(FinancialYearClosureRequest $request)
    {
        return response()->json(['message' => 'Financial year closure saved.', 'closure' => $this->financialReports->closeYear($request->validated())], 201);
    }

    public function reopenYear(FinancialYearReopenRequest $request, int $closure)
    {
        return response()->json(['message' => 'Financial year reopened.', 'closure' => $this->financialReports->reopenYear($closure, $request->validated()['reopen_reason'])]);
    }

    public function snapshot(FinancialReportSnapshotRequest $request)
    {
        return response()->json(['message' => 'Financial report snapshot created.', 'snapshot' => $this->financialReports->createSnapshot($request->validated())], 201);
    }
}
