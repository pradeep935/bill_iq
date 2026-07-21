<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Branch;
use App\Models\FinancialReportException;
use App\Models\FinancialReportSnapshot;
use App\Models\FinancialYearClosure;
use App\Models\JournalEntry;
use App\Models\JournalVoucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialReportService
{
    private AccountingPostingService $posting;

    public function __construct(AccountingPostingService $posting)
    {
        $this->posting = $posting;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        return [
            'accounts' => Account::query()->with('group')->where('business_id', $businessId)->orderBy('account_name')->get(),
            'groups' => AccountGroup::query()->where('business_id', $businessId)->orderBy('report_order')->orderBy('group_name')->get(),
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'periods' => DB::table('accounting_periods')->where('business_id', $businessId)->orderByDesc('start_date')->get(),
        ];
    }

    public function saveClassification(array $data)
    {
        if ($data['target_type'] === 'group') {
            $group = AccountGroup::query()->where('business_id', AppController::businessId())->findOrFail($data['target_id']);
            $group->fill([
                'financial_statement_type' => $data['financial_statement_type'] ?? $group->financial_statement_type,
                'financial_statement_section' => $data['financial_statement_section'],
                'cash_flow_category' => $data['cash_flow_category'] ?? null,
                'report_order' => $data['report_order'] ?? $group->report_order,
                'normal_balance' => $data['normal_balance'] ?? $group->normal_balance,
                'is_control_group' => (bool) ($data['is_control_group'] ?? $group->is_control_group),
            ])->save();
            return $group->fresh();
        }

        $account = Account::query()->where('business_id', AppController::businessId())->findOrFail($data['target_id']);
        $account->fill([
            'financial_statement_section' => $data['financial_statement_section'],
            'cash_flow_category' => $data['cash_flow_category'] ?? null,
            'report_order' => $data['report_order'] ?? $account->report_order,
            'is_control_account' => (bool) ($data['is_control_account'] ?? $account->is_control_account),
        ])->save();
        return $account->fresh('group');
    }

    public function getOpeningBalance(int $accountId, array $filters = []): float
    {
        $dateFrom = $this->dateFrom($filters);
        if (!$dateFrom) return 0.0;
        $account = $this->account($accountId);
        $row = $this->entries($filters, true)->where('journal_entries.account_id', $accountId)->whereDate('journal_vouchers.voucher_date', '<', $dateFrom)->selectRaw('COALESCE(SUM(journal_entries.debit_amount),0) as debit, COALESCE(SUM(journal_entries.credit_amount),0) as credit')->first();
        return $this->signedBalance($account, (float) $row->debit, (float) $row->credit);
    }

    public function getAccountTransactions(int $accountId, array $filters = [])
    {
        $opening = $this->getOpeningBalance($accountId, $filters);
        $running = $opening;
        $account = $this->account($accountId);
        return $this->entries($filters)->where('journal_entries.account_id', $accountId)
            ->orderBy('journal_vouchers.voucher_date')->orderBy('journal_entries.id')
            ->get($this->entryColumns())
            ->map(function ($row) use (&$running, $account) {
                $running += $this->signedBalance($account, (float) $row->debit_amount, (float) $row->credit_amount);
                $row->running_balance = round($running, 2);
                $row->balance_type = $running >= 0 ? $this->normalBalance($account) : $this->oppositeBalance($this->normalBalance($account));
                return $row;
            });
    }

    public function getAccountClosingBalance(int $accountId, array $filters = []): float
    {
        $account = $this->account($accountId);
        $row = $this->entries($filters, true)->where('journal_entries.account_id', $accountId)->selectRaw('COALESCE(SUM(journal_entries.debit_amount),0) as debit, COALESCE(SUM(journal_entries.credit_amount),0) as credit')->first();
        return $this->signedBalance($account, (float) $row->debit, (float) $row->credit);
    }

    public function getGroupBalance(int $groupId, array $filters = []): float
    {
        $accountIds = Account::query()->where('business_id', AppController::businessId())->where('account_group_id', $groupId)->pluck('id');
        return round($accountIds->sum(fn ($id) => $this->getAccountClosingBalance((int) $id, $filters)), 2);
    }

    public function getDayBook(array $filters = []): array
    {
        $query = JournalVoucher::query()->with(['creator', 'approver'])->where('business_id', AppController::businessId())->whereIn('status', $this->statuses($filters))
            ->when(!empty($filters['voucher_type']), fn (Builder $q) => $q->where('voucher_type', $filters['voucher_type']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('voucher_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('voucher_date', '<=', $filters['date_to']))
            ->when(!empty($filters['amount_min']), fn (Builder $q) => $q->where('total_debit', '>=', $filters['amount_min']))
            ->when(!empty($filters['amount_max']), fn (Builder $q) => $q->where('total_debit', '<=', $filters['amount_max']));

        $rows = $query->latest('voucher_date')->paginate(min(max((int) ($filters['per_page'] ?? 50), 1), 200));
        return ['items' => $rows->getCollection()->values(), 'pagination' => $this->pagination($rows), 'totals' => ['debit' => (float) (clone $query)->sum('total_debit'), 'credit' => (float) (clone $query)->sum('total_credit')]];
    }

    public function getJournalRegister(array $filters = []): array
    {
        $rows = $this->entries($filters)->orderBy('journal_vouchers.voucher_date')->orderBy('journal_vouchers.voucher_number')->orderBy('journal_entries.id')->paginate(min(max((int) ($filters['per_page'] ?? 100), 1), 300), $this->entryColumns());
        return ['items' => $rows->getCollection()->values(), 'pagination' => $this->pagination($rows), 'totals' => ['debit' => round($rows->getCollection()->sum('debit_amount'), 2), 'credit' => round($rows->getCollection()->sum('credit_amount'), 2)]];
    }

    public function getLedgerReport(array $filters = []): array
    {
        if (empty($filters['account_id'])) throw ValidationException::withMessages(['account_id' => 'Account is required.']);
        $account = $this->account((int) $filters['account_id']);
        $opening = $this->getOpeningBalance($account->id, $filters);
        $transactions = $this->getAccountTransactions($account->id, $filters);
        $periodDebit = round($transactions->sum('debit_amount'), 2);
        $periodCredit = round($transactions->sum('credit_amount'), 2);
        $closing = round($opening + $this->signedBalance($account, $periodDebit, $periodCredit), 2);
        return ['account' => $account->load('group'), 'opening_balance' => $opening, 'period_debit' => $periodDebit, 'period_credit' => $periodCredit, 'closing_balance' => $closing, 'transactions' => $transactions];
    }

    public function getTrialBalance(array $filters = []): array
    {
        $accounts = Account::query()->with('group')->where('business_id', AppController::businessId())->orderBy('account_code')->get();
        $rows = $accounts->map(function (Account $account) use ($filters) {
            $opening = $this->getOpeningBalance($account->id, $filters);
            $period = $this->periodMovement($account->id, $filters);
            $closing = round($opening + $this->signedBalance($account, $period['debit'], $period['credit']), 2);
            return [
                'group_id' => $account->account_group_id,
                'group_name' => optional($account->group)->group_name,
                'account_id' => $account->id,
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'opening_debit' => $opening >= 0 && $this->normalBalance($account) === 'debit' ? abs($opening) : 0,
                'opening_credit' => $opening >= 0 && $this->normalBalance($account) === 'credit' ? abs($opening) : ($opening < 0 ? abs($opening) : 0),
                'period_debit' => $period['debit'],
                'period_credit' => $period['credit'],
                'closing_debit' => $closing >= 0 && $this->normalBalance($account) === 'debit' ? abs($closing) : ($closing < 0 && $this->normalBalance($account) === 'credit' ? abs($closing) : 0),
                'closing_credit' => $closing >= 0 && $this->normalBalance($account) === 'credit' ? abs($closing) : ($closing < 0 && $this->normalBalance($account) === 'debit' ? abs($closing) : 0),
            ];
        })->filter(fn ($row) => !empty($filters['include_zero']) || round($row['opening_debit'] + $row['opening_credit'] + $row['period_debit'] + $row['period_credit'] + $row['closing_debit'] + $row['closing_credit'], 2) != 0.0)->values();

        $totals = [
            'opening_debit' => round($rows->sum('opening_debit'), 2),
            'opening_credit' => round($rows->sum('opening_credit'), 2),
            'period_debit' => round($rows->sum('period_debit'), 2),
            'period_credit' => round($rows->sum('period_credit'), 2),
            'closing_debit' => round($rows->sum('closing_debit'), 2),
            'closing_credit' => round($rows->sum('closing_credit'), 2),
        ];
        return ['rows' => $rows, 'groups' => $rows->groupBy('group_name')->map(fn ($items) => ['group_name' => $items->first()['group_name'], 'closing_debit' => round($items->sum('closing_debit'), 2), 'closing_credit' => round($items->sum('closing_credit'), 2)])->values(), 'totals' => $totals, 'is_balanced' => round($totals['closing_debit'], 2) === round($totals['closing_credit'], 2), 'exceptions' => $this->exceptionReport($filters, false)];
    }

    public function getProfitAndLoss(array $filters = []): array
    {
        $sections = $this->statementSections('profit_and_loss', $filters);
        $revenue = $this->sectionTotal($sections, ['Sales Revenue', 'Other Operating Revenue', 'Other Income']);
        $cost = $this->sectionTotal($sections, ['Cost of Goods Sold', 'Direct Expenses']);
        $expenses = $this->sectionTotal($sections, ['Indirect Expenses', 'Finance Costs', 'Depreciation', 'Tax Expense']);
        $grossProfit = round($revenue - $cost, 2);
        $netProfit = round($grossProfit - $expenses, 2);
        return ['sections' => $sections, 'trading' => ['opening_stock' => 0, 'net_purchases' => $cost, 'closing_stock' => $this->inventoryValue($filters), 'cogs_note' => 'Perpetual inventory accounting is assumed where posted COGS/inventory journals exist.'], 'gross_profit' => $grossProfit, 'net_profit' => $netProfit, 'gross_profit_percent' => $revenue != 0.0 ? round($grossProfit / $revenue * 100, 2) : null, 'net_profit_percent' => $revenue != 0.0 ? round($netProfit / $revenue * 100, 2) : null];
    }

    public function getBalanceSheet(array $filters = []): array
    {
        $filters['date_to'] = $this->dateTo($filters);
        unset($filters['date_from']);
        $sections = $this->statementSections('balance_sheet', $filters, true);
        $assets = $this->sectionTypeTotal($sections, ['Current Assets', 'Non-Current Assets', 'Cash and Cash Equivalents', 'Bank Accounts', 'Accounts Receivable', 'Inventory', 'Loans and Advances', 'Fixed Assets', 'Other Assets']);
        $liabilities = $this->sectionTypeTotal($sections, ['Current Liabilities', 'Accounts Payable', 'Tax Liabilities', 'Loans and Borrowings', 'Other Liabilities']);
        $equity = $this->sectionTypeTotal($sections, ['Capital', 'Reserves and Surplus', 'Retained Earnings']);
        $currentProfit = $this->getProfitAndLoss($filters)['net_profit'];
        $rightSide = round($liabilities + $equity + $currentProfit, 2);
        return ['sections' => $sections, 'assets_total' => round($assets, 2), 'liabilities_total' => round($liabilities, 2), 'equity_total' => round($equity, 2), 'current_period_profit' => $currentProfit, 'liabilities_equity_total' => $rightSide, 'is_balanced' => round($assets, 2) === $rightSide, 'difference' => round($assets - $rightSide, 2)];
    }

    public function getCashFlow(array $filters = []): array
    {
        $opening = $this->cashEquivalentBalance($filters, true);
        $closing = $this->cashEquivalentBalance($filters, false);
        $cashEntries = $this->entries($filters)->whereIn('accounts.account_type', ['cash', 'bank'])->where('journal_vouchers.voucher_type', '!=', 'contra')->get($this->entryColumns());
        $operating = round($cashEntries->where('cash_flow_category', 'operating')->sum(fn ($r) => (float) $r->debit_amount - (float) $r->credit_amount), 2);
        $investing = round($cashEntries->where('cash_flow_category', 'investing')->sum(fn ($r) => (float) $r->debit_amount - (float) $r->credit_amount), 2);
        $financing = round($cashEntries->where('cash_flow_category', 'financing')->sum(fn ($r) => (float) $r->debit_amount - (float) $r->credit_amount), 2);
        $net = round($operating + $investing + $financing, 2);
        return ['opening_cash_equivalents' => $opening, 'operating_activities' => $operating, 'investing_activities' => $investing, 'financing_activities' => $financing, 'net_increase_decrease' => $net, 'closing_cash_equivalents' => $closing, 'reconciles' => round($opening + $net, 2) === round($closing, 2), 'direct_method_note' => 'Direct method foundation is available from cash and bank journal lines; contra vouchers are excluded.'];
    }

    public function getReceivableSummary(array $filters = []): array
    {
        return $this->partySummary('customer', $filters);
    }

    public function getPayableSummary(array $filters = []): array
    {
        return $this->partySummary('supplier', $filters);
    }

    public function getComparativeReport(array $filters = []): array
    {
        $current = $this->getProfitAndLoss($filters);
        $comparison = $this->getProfitAndLoss(['date_from' => $filters['compare_from'] ?? null, 'date_to' => $filters['compare_to'] ?? null, 'branch_id' => $filters['compare_branch_id'] ?? ($filters['branch_id'] ?? null)]);
        $difference = round($current['net_profit'] - $comparison['net_profit'], 2);
        return ['current' => $current, 'comparison' => $comparison, 'difference' => $difference, 'percent_change' => $comparison['net_profit'] != 0.0 ? round($difference / abs($comparison['net_profit']) * 100, 2) : null];
    }

    public function getBranchFinancials(array $filters = []): array
    {
        return Branch::query()->where('business_id', AppController::businessId())->get(['id', 'name'])->map(function ($branch) use ($filters) {
            $branchFilters = array_merge($filters, ['branch_id' => $branch->id]);
            return ['branch_id' => $branch->id, 'branch_name' => $branch->name, 'trial_balance' => $this->getTrialBalance($branchFilters)['totals'], 'profit_and_loss' => $this->getProfitAndLoss($branchFilters), 'cash_flow' => $this->getCashFlow($branchFilters)];
        })->values();
    }

    public function getAccountSchedule(array $filters = []): array
    {
        $section = $filters['section'] ?? null;
        if (!$section) throw ValidationException::withMessages(['section' => 'Schedule section is required.']);
        $accounts = Account::query()->where('business_id', AppController::businessId())->where(function (Builder $q) use ($section) {
            $q->where('financial_statement_section', $section)->orWhereHas('group', fn (Builder $g) => $g->where('financial_statement_section', $section));
        })->get();
        $rows = $accounts->map(fn ($account) => ['account_id' => $account->id, 'account_code' => $account->account_code, 'account_name' => $account->account_name, 'opening_balance' => $this->getOpeningBalance($account->id, $filters), 'movement' => $this->periodMovement($account->id, $filters), 'closing_balance' => $this->getAccountClosingBalance($account->id, $filters)])->values();
        return ['section' => $section, 'rows' => $rows, 'closing_total' => round($rows->sum('closing_balance'), 2)];
    }

    public function ratios(array $filters = []): array
    {
        $pl = $this->getProfitAndLoss($filters);
        $bs = $this->getBalanceSheet($filters);
        $receivables = $this->sectionFromBalanceSheet($bs, 'Accounts Receivable');
        $payables = $this->sectionFromBalanceSheet($bs, 'Accounts Payable');
        $inventory = $this->sectionFromBalanceSheet($bs, 'Inventory');
        $currentAssets = $bs['assets_total'];
        $currentLiabilities = $bs['liabilities_total'];
        return [
            'gross_profit_margin' => $this->ratio($pl['gross_profit'], $this->sectionRevenue($pl), 'Gross Profit / Revenue'),
            'net_profit_margin' => $this->ratio($pl['net_profit'], $this->sectionRevenue($pl), 'Net Profit / Revenue'),
            'current_ratio' => $this->ratio($currentAssets, $currentLiabilities, 'Current Assets / Current Liabilities'),
            'quick_ratio' => $this->ratio($currentAssets - $inventory, $currentLiabilities, '(Current Assets - Inventory) / Current Liabilities'),
            'debt_to_equity' => $this->ratio($bs['liabilities_total'], $bs['equity_total'] + $bs['current_period_profit'], 'Liabilities / Equity'),
            'inventory_turnover' => $this->ratio($this->sectionTotal($pl['sections'], ['Cost of Goods Sold']), $inventory, 'COGS / Inventory'),
            'receivable_turnover' => $this->ratio($this->sectionRevenue($pl), $receivables, 'Revenue / Receivables'),
            'payable_turnover' => $this->ratio($this->sectionTotal($pl['sections'], ['Cost of Goods Sold']), $payables, 'Purchases or COGS / Payables'),
        ];
    }

    public function dashboard(array $filters = []): array
    {
        $pl = $this->getProfitAndLoss($filters);
        $bs = $this->getBalanceSheet($filters);
        return [
            'total_revenue' => $this->sectionRevenue($pl),
            'gross_profit' => $pl['gross_profit'],
            'net_profit' => $pl['net_profit'],
            'cash_balance' => $this->accountTypeBalance('cash', $filters),
            'bank_balance' => $this->accountTypeBalance('bank', $filters),
            'receivables' => $this->sectionFromBalanceSheet($bs, 'Accounts Receivable'),
            'payables' => $this->sectionFromBalanceSheet($bs, 'Accounts Payable'),
            'inventory_value' => $this->sectionFromBalanceSheet($bs, 'Inventory'),
            'gst_payable' => $this->sectionFromBalanceSheet($bs, 'Tax Liabilities'),
            'expenses' => $this->sectionTotal($pl['sections'], ['Indirect Expenses', 'Finance Costs', 'Direct Expenses']),
            'overdue_receivables' => $this->getReceivableSummary($filters)['totals']['overdue_amount'],
            'overdue_payables' => $this->getPayableSummary($filters)['totals']['overdue_amount'],
            'revenue_trend' => $this->monthlyTrend('income', $filters),
            'expense_trend' => $this->monthlyTrend('expense', $filters),
        ];
    }

    public function exceptionReport(array $filters = [], bool $persist = true): array
    {
        $businessId = AppController::businessId();
        $exceptions = [];
        $unbalanced = JournalVoucher::query()->where('business_id', $businessId)->whereIn('status', ['approved', 'posted'])->whereRaw('ROUND(total_debit, 2) <> ROUND(total_credit, 2)')->get();
        foreach ($unbalanced as $voucher) $exceptions[] = $this->exceptionRow('Trial Balance mismatch', 'critical', JournalVoucher::class, $voucher->id, $voucher->voucher_number, 'Journal voucher is not balanced.', 'Reverse and repost the source voucher.');
        $unmapped = Account::query()->where('business_id', $businessId)->where('status', 'active')->where(function (Builder $q) {
            $q->whereNull('financial_statement_section')->orWhere('financial_statement_section', '');
        })->get();
        foreach ($unmapped as $account) $exceptions[] = $this->exceptionRow('Missing account classification', 'warning', Account::class, $account->id, $account->account_code, 'Account is missing financial statement classification.', 'Assign account group or report section.');
        $orphans = JournalEntry::query()->leftJoin('journal_vouchers', 'journal_vouchers.id', '=', 'journal_entries.journal_voucher_id')->where('journal_entries.business_id', $businessId)->whereNull('journal_vouchers.id')->get(['journal_entries.id']);
        foreach ($orphans as $entry) $exceptions[] = $this->exceptionRow('Orphan journal entries', 'critical', JournalEntry::class, $entry->id, null, 'Journal entry has no voucher.', 'Investigate data integrity.');
        if ($persist) $this->syncExceptions($exceptions);
        return $exceptions;
    }

    public function closingChecklist(array $data): array
    {
        $filters = ['date_to' => $data['closing_date']];
        $tb = $this->getTrialBalance($filters);
        return [
            'trial_balance_balanced' => $tb['is_balanced'],
            'unposted_vouchers' => JournalVoucher::query()->where('business_id', AppController::businessId())->whereIn('status', ['draft', 'submitted'])->count(),
            'exceptions' => $this->exceptionReport($filters, false),
            'receivables_reviewed' => false,
            'payables_reviewed' => false,
            'gst_reviewed' => false,
            'inventory_reconciled' => false,
        ];
    }

    public function closeYear(array $data): FinancialYearClosure
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $closure = FinancialYearClosure::query()->firstOrNew(['business_id' => $businessId, 'financial_year' => $data['financial_year']]);
            if ($closure->exists && $closure->status === 'closed') throw ValidationException::withMessages(['financial_year' => 'Financial year is already closed.']);
            $checklist = $this->closingChecklist($data);
            if (!$checklist['trial_balance_balanced']) throw ValidationException::withMessages(['trial_balance' => 'Financial year cannot close with unbalanced trial balance.']);
            $profit = $this->getProfitAndLoss(['date_to' => $data['closing_date']])['net_profit'];
            $closure->fill(['closing_date' => $data['closing_date'], 'status' => $data['status'] ?? 'under_review', 'profit_loss_amount' => $profit, 'retained_earnings_account_id' => $data['retained_earnings_account_id'] ?? $this->retainedEarningsAccountId(), 'checklist_json' => $checklist]);
            if (($data['status'] ?? '') === 'closed') {
                $closure->closed_by = Auth::id();
                $closure->closed_at = now();
            }
            $closure->save();
            return $closure->fresh();
        });
    }

    public function reopenYear(int $id, string $reason): FinancialYearClosure
    {
        $closure = FinancialYearClosure::query()->where('business_id', AppController::businessId())->findOrFail($id);
        $closure->update(['status' => 'reopened', 'reopened_by' => Auth::id(), 'reopened_at' => now(), 'reopen_reason' => $reason]);
        return $closure->fresh();
    }

    public function createSnapshot(array $data): FinancialReportSnapshot
    {
        $report = $this->reportByType($data['report_type'], $data);
        $version = FinancialReportSnapshot::query()->where('business_id', AppController::businessId())->where('report_type', $data['report_type'])->where('financial_year', $data['financial_year'] ?? null)->max('version_number') ?: 0;
        return FinancialReportSnapshot::query()->create(['business_id' => AppController::businessId(), 'report_type' => $data['report_type'], 'financial_year' => $data['financial_year'] ?? null, 'period_start' => $data['period_start'] ?? null, 'period_end' => $data['period_end'] ?? null, 'branch_id' => $data['branch_id'] ?? null, 'version_number' => $version + 1, 'snapshot_json' => $report, 'status' => $data['status'] ?? 'draft', 'generated_by' => Auth::id(), 'generated_at' => now()]);
    }

    private function reportByType(string $type, array $filters): array
    {
        $filters['date_from'] = $filters['period_start'] ?? ($filters['date_from'] ?? null);
        $filters['date_to'] = $filters['period_end'] ?? ($filters['date_to'] ?? null);
        if ($type === 'trial_balance') return $this->getTrialBalance($filters);
        if ($type === 'profit_and_loss') return $this->getProfitAndLoss($filters);
        if ($type === 'balance_sheet') return $this->getBalanceSheet($filters);
        return $this->getCashFlow($filters);
    }

    private function entries(array $filters = [], bool $ignoreDateFrom = false)
    {
        return DB::table('journal_entries')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_entries.journal_voucher_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entries.account_id')
            ->leftJoin('account_groups', 'account_groups.id', '=', 'accounts.account_group_id')
            ->leftJoin('branches', 'branches.id', '=', 'journal_entries.branch_id')
            ->where('journal_entries.business_id', AppController::businessId())
            ->whereIn('journal_vouchers.status', $this->statuses($filters))
            ->when(!$ignoreDateFrom && !empty($filters['date_from']), fn ($q) => $q->whereDate('journal_vouchers.voucher_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('journal_vouchers.voucher_date', '<=', $filters['date_to']))
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('journal_entries.branch_id', $filters['branch_id']))
            ->when(!empty($filters['account_id']), fn ($q) => $q->where('journal_entries.account_id', $filters['account_id']))
            ->when(!empty($filters['voucher_type']), fn ($q) => $q->where('journal_vouchers.voucher_type', $filters['voucher_type']))
            ->when(!empty($filters['customer_id']), fn ($q) => $q->where('journal_entries.customer_id', $filters['customer_id']))
            ->when(!empty($filters['supplier_id']), fn ($q) => $q->where('journal_entries.supplier_id', $filters['supplier_id']));
    }

    private function entryColumns(): array
    {
        return ['journal_entries.id', 'journal_vouchers.id as voucher_id', 'journal_vouchers.voucher_date', 'journal_vouchers.voucher_type', 'journal_vouchers.voucher_number', 'journal_vouchers.reference_type', 'journal_vouchers.reference_number', 'journal_vouchers.status', 'journal_entries.account_id', 'accounts.account_code', 'accounts.account_name', 'accounts.account_type', 'accounts.cash_flow_category', 'account_groups.group_name', 'account_groups.nature', 'branches.name as branch_name', 'journal_entries.customer_id', 'journal_entries.supplier_id', 'journal_entries.narration', 'journal_entries.debit_amount', 'journal_entries.credit_amount'];
    }

    private function statementSections(string $type, array $filters, bool $closing = false): array
    {
        $accounts = Account::query()->with('group')->where('business_id', AppController::businessId())->whereHas('group', fn (Builder $q) => $q->where('financial_statement_type', $type))->orderBy('report_order')->get();
        return $accounts->groupBy(fn ($a) => $a->financial_statement_section ?: optional($a->group)->financial_statement_section ?: 'Unmapped')->map(function ($items, $section) use ($filters, $closing) {
            $rows = $items->map(function ($account) use ($filters, $closing) {
                $balance = $closing ? $this->getAccountClosingBalance($account->id, $filters) : $this->periodNet($account, $filters);
                return ['account_id' => $account->id, 'account_code' => $account->account_code, 'account_name' => $account->account_name, 'amount' => round($balance, 2)];
            })->filter(fn ($row) => round($row['amount'], 2) != 0.0)->values();
            return ['section' => $section, 'accounts' => $rows, 'total' => round($rows->sum('amount'), 2)];
        })->values()->all();
    }

    private function periodMovement(int $accountId, array $filters): array
    {
        $row = $this->entries($filters)->where('journal_entries.account_id', $accountId)->selectRaw('COALESCE(SUM(journal_entries.debit_amount),0) as debit, COALESCE(SUM(journal_entries.credit_amount),0) as credit')->first();
        return ['debit' => round((float) $row->debit, 2), 'credit' => round((float) $row->credit, 2)];
    }

    private function periodNet(Account $account, array $filters): float
    {
        $movement = $this->periodMovement($account->id, $filters);
        return $this->signedBalance($account, $movement['debit'], $movement['credit']);
    }

    private function partySummary(string $party, array $filters): array
    {
        $column = $party . '_id';
        $rows = $this->entries($filters)->whereNotNull('journal_entries.' . $column)->selectRaw("journal_entries.$column as party_id, COALESCE(SUM(journal_entries.debit_amount),0) as debit, COALESCE(SUM(journal_entries.credit_amount),0) as credit")->groupBy('journal_entries.' . $column)->get()->map(function ($row) use ($party) {
            $closing = $party === 'customer' ? (float) $row->debit - (float) $row->credit : (float) $row->credit - (float) $row->debit;
            return ['party_id' => $row->party_id, 'debit' => (float) $row->debit, 'credit' => (float) $row->credit, 'closing_balance' => round($closing, 2), 'overdue_amount' => max(0, round($closing, 2)), 'advance_amount' => max(0, round(-1 * $closing, 2)), 'ageing' => ['current' => max(0, round($closing, 2)), '1_30' => 0, '31_60' => 0, '61_90' => 0, '91_180' => 0, 'above_180' => 0]];
        })->values();
        return ['rows' => $rows, 'totals' => ['closing_balance' => round($rows->sum('closing_balance'), 2), 'overdue_amount' => round($rows->sum('overdue_amount'), 2), 'advance_amount' => round($rows->sum('advance_amount'), 2)]];
    }

    private function signedBalance(Account $account, float $debit, float $credit): float
    {
        return $this->normalBalance($account) === 'debit' ? round($debit - $credit, 2) : round($credit - $debit, 2);
    }

    private function normalBalance(Account $account): string
    {
        return optional($account->group)->normal_balance ?: (in_array($account->account_type, ['asset', 'expense', 'cash', 'bank', 'customer', 'inventory'], true) ? 'debit' : 'credit');
    }

    private function oppositeBalance(string $normal): string
    {
        return $normal === 'debit' ? 'credit' : 'debit';
    }

    private function account(int $id): Account
    {
        return Account::query()->with('group')->where('business_id', AppController::businessId())->findOrFail($id);
    }

    private function statuses(array $filters): array
    {
        return !empty($filters['include_draft']) ? ['draft', 'submitted', 'approved', 'posted'] : ['approved', 'posted'];
    }

    private function dateFrom(array $filters): ?string
    {
        return $filters['date_from'] ?? null;
    }

    private function dateTo(array $filters): string
    {
        return $filters['date_to'] ?? now()->toDateString();
    }

    private function sectionTotal(array $sections, array $names): float
    {
        return round(collect($sections)->whereIn('section', $names)->sum('total'), 2);
    }

    private function sectionTypeTotal(array $sections, array $names): float
    {
        return round(collect($sections)->whereIn('section', $names)->sum('total'), 2);
    }

    private function sectionFromBalanceSheet(array $bs, string $section): float
    {
        return round(collect($bs['sections'])->firstWhere('section', $section)['total'] ?? 0, 2);
    }

    private function sectionRevenue(array $pl): float
    {
        return $this->sectionTotal($pl['sections'], ['Sales Revenue', 'Other Operating Revenue', 'Other Income']);
    }

    private function accountTypeBalance(string $type, array $filters): float
    {
        return round(Account::query()->where('business_id', AppController::businessId())->where('account_type', $type)->get()->sum(fn ($a) => $this->getAccountClosingBalance($a->id, $filters)), 2);
    }

    private function cashEquivalentBalance(array $filters, bool $opening): float
    {
        $f = $opening ? array_merge($filters, ['date_to' => $filters['date_from'] ?? null]) : $filters;
        return round($this->accountTypeBalance('cash', $f) + $this->accountTypeBalance('bank', $f), 2);
    }

    private function inventoryValue(array $filters): float
    {
        return (float) DB::table('stock_ledgers')->where('business_id', AppController::businessId())->when(!empty($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('transaction_date', '<=', $filters['date_to']))->selectRaw('COALESCE(SUM((quantity_in - quantity_out) * unit_cost), 0) as value')->value('value');
    }

    private function monthlyTrend(string $accountType, array $filters): array
    {
        return $this->entries($filters)->where('accounts.account_type', $accountType)->selectRaw('DATE_FORMAT(journal_vouchers.voucher_date, "%Y-%m") as month, COALESCE(SUM(journal_entries.credit_amount - journal_entries.debit_amount),0) as amount')->groupBy('month')->orderBy('month')->get()->toArray();
    }

    private function ratio(float $numerator, float $denominator, string $formula): array
    {
        return ['formula' => $formula, 'value' => $denominator == 0.0 ? null : round($numerator / $denominator, 2), 'explanation' => $denominator == 0.0 ? 'Not available because denominator is zero.' : null];
    }

    private function retainedEarningsAccountId(): ?int
    {
        return Account::query()->where('business_id', AppController::businessId())->where('account_type', 'equity')->value('id') ?: Account::query()->where('business_id', AppController::businessId())->where('account_type', 'capital')->value('id');
    }

    private function exceptionRow(string $type, string $severity, ?string $sourceType, ?int $sourceId, ?string $sourceNumber, string $message, string $action): array
    {
        return ['exception_type' => $type, 'severity' => $severity, 'source_type' => $sourceType, 'source_id' => $sourceId, 'source_number' => $sourceNumber, 'message' => $message, 'suggested_action' => $action, 'resolution_status' => 'open'];
    }

    private function syncExceptions(array $exceptions): void
    {
        foreach ($exceptions as $exception) {
            FinancialReportException::query()->updateOrCreate(['business_id' => AppController::businessId(), 'exception_type' => $exception['exception_type'], 'source_type' => $exception['source_type'], 'source_id' => $exception['source_id']], $exception);
        }
    }

    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()];
    }
}
