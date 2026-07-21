<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\ExpenseCategory;
use App\Models\ExpenseVoucher;
use App\Models\IncomeCategory;
use App\Models\JournalEntry;
use App\Models\OtherIncomeVoucher;
use App\Models\PettyCashAdvance;
use App\Models\RecurringExpenseTemplate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseIncomeService
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
            'expense_categories' => ExpenseCategory::query()->with('account')->where('business_id', $businessId)->where('status', 'active')->orderBy('category_name')->get(),
            'income_categories' => IncomeCategory::query()->with('account')->where('business_id', $businessId)->where('status', 'active')->orderBy('category_name')->get(),
            'expense_accounts' => Account::query()->where('business_id', $businessId)->where('account_type', 'expense')->where('status', 'active')->orderBy('account_name')->get(),
            'income_accounts' => Account::query()->where('business_id', $businessId)->where('account_type', 'income')->where('status', 'active')->orderBy('account_name')->get(),
            'cash_bank_accounts' => Account::query()->where('business_id', $businessId)->whereIn('account_type', ['cash', 'bank'])->where('status', 'active')->orderBy('account_name')->get()->map(function ($account) {
                $account->masked_account_number = $account->maskAccountNumber();
                return $account;
            })->values(),
            'suppliers' => DB::table('suppliers')->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->limit(200)->get(['id', 'name']),
            'customers' => DB::table('customers')->where('business_id', $businessId)->where('status', 'active')->orderBy('customer_name')->limit(200)->get(['id', 'customer_name']),
            'branches' => DB::table('branches')->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
        ];
    }

    public function expenseCategories(array $filters)
    {
        return ExpenseCategory::query()->with(['parent', 'account'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $q->where(function (Builder $inner) use ($filters) {
                    $inner->where('category_name', 'like', '%' . $filters['search'] . '%')->orWhere('category_code', 'like', '%' . $filters['search'] . '%');
                });
            })->latest('id')->paginate(50);
    }

    public function saveExpenseCategory(array $data, ?int $id = null): ExpenseCategory
    {
        $businessId = AppController::businessId();
        $this->assertAccount($data['expense_account_id'], ['expense']);
        if (!empty($data['parent_id'])) $this->assertExpenseCategory($data['parent_id']);
        $category = $id ? ExpenseCategory::query()->where('business_id', $businessId)->findOrFail($id) : new ExpenseCategory(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $category->fill([
            'parent_id' => $data['parent_id'] ?? null,
            'category_code' => $data['category_code'],
            'category_name' => $data['category_name'],
            'name' => $data['category_name'],
            'expense_account_id' => $data['expense_account_id'],
            'account_id' => $data['expense_account_id'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'updated_by' => Auth::id(),
        ])->save();
        return $category->fresh(['parent', 'account']);
    }

    public function deleteExpenseCategory(int $id, bool $force = false): void
    {
        $category = ExpenseCategory::query()->withTrashed()->where('business_id', AppController::businessId())->findOrFail($id);
        if ($category->is_system) throw ValidationException::withMessages(['category' => 'System expense categories cannot be deleted.']);
        if ($force && $category->vouchers()->exists()) throw ValidationException::withMessages(['category' => 'Expense categories used in vouchers cannot be permanently deleted.']);
        $force ? $category->forceDelete() : $category->delete();
    }

    public function incomeCategories(array $filters)
    {
        return IncomeCategory::query()->with(['parent', 'account'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $q->where(function (Builder $inner) use ($filters) {
                    $inner->where('category_name', 'like', '%' . $filters['search'] . '%')->orWhere('category_code', 'like', '%' . $filters['search'] . '%');
                });
            })->latest('id')->paginate(50);
    }

    public function saveIncomeCategory(array $data, ?int $id = null): IncomeCategory
    {
        $businessId = AppController::businessId();
        $this->assertAccount($data['income_account_id'], ['income']);
        if (!empty($data['parent_id'])) $this->assertIncomeCategory($data['parent_id']);
        $category = $id ? IncomeCategory::query()->where('business_id', $businessId)->findOrFail($id) : new IncomeCategory(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $category->fill([
            'parent_id' => $data['parent_id'] ?? null,
            'category_code' => $data['category_code'],
            'category_name' => $data['category_name'],
            'income_account_id' => $data['income_account_id'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'active',
            'updated_by' => Auth::id(),
        ])->save();
        return $category->fresh(['parent', 'account']);
    }

    public function deleteIncomeCategory(int $id, bool $force = false): void
    {
        $category = IncomeCategory::query()->withTrashed()->where('business_id', AppController::businessId())->findOrFail($id);
        if ($category->is_system) throw ValidationException::withMessages(['category' => 'System income categories cannot be deleted.']);
        if ($force && $category->vouchers()->exists()) throw ValidationException::withMessages(['category' => 'Income categories used in vouchers cannot be permanently deleted.']);
        $force ? $category->forceDelete() : $category->delete();
    }

    public function expenses(array $filters)
    {
        return ExpenseVoucher::query()->with(['category', 'paidFromAccount', 'supplier', 'branch'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $q->where(function (Builder $inner) use ($filters) {
                    $inner->where('voucher_number', 'like', '%' . $filters['search'] . '%')->orWhere('party_name', 'like', '%' . $filters['search'] . '%')->orWhere('invoice_number', 'like', '%' . $filters['search'] . '%');
                });
            })
            ->when(!empty($filters['category_id']), fn (Builder $q) => $q->where('expense_category_id', $filters['category_id']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['payment_status']), fn (Builder $q) => $q->where('payment_status', $filters['payment_status']))
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('expense_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('expense_date', '<=', $filters['date_to']))
            ->latest('id')->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    public function saveExpense(array $data, ?int $id = null): ExpenseVoucher
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $expense = $id ? ExpenseVoucher::query()->where('business_id', $businessId)->with('items')->findOrFail($id) : new ExpenseVoucher(['business_id' => $businessId, 'created_by' => Auth::id(), 'voucher_number' => $this->nextNumber('EXP', ExpenseVoucher::class, 'voucher_number')]);
            if (in_array($expense->status, ['approved', 'posted', 'reversed', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Posted expenses cannot be directly edited.']);

            $this->assertExpenseCategory($data['expense_category_id']);
            $this->assertAccount($data['expense_account_id'], ['expense']);
            if (!empty($data['paid_from_account_id'])) $this->assertAccount($data['paid_from_account_id'], ['cash', 'bank']);
            $rawItems = $data['items'];
            unset($data['items']);
            $items = $this->calculateExpenseItems($rawItems, $data['tax_type']);
            $totals = $this->expenseTotals($items, (bool) ($data['tds_applicable'] ?? false), (float) ($data['tds_rate'] ?? 0));

            if ($data['payment_mode'] !== 'unpaid' && empty($data['paid_from_account_id'])) {
                throw ValidationException::withMessages(['paid_from_account_id' => 'Paid expenses require a cash or bank account.']);
            }
            if ($data['payment_mode'] === 'unpaid') $data['payment_status'] = 'unpaid';

            $expense->fill(array_merge($data, $totals, [
                'net_paid_amount' => $data['payment_status'] === 'unpaid' ? 0 : $totals['net_paid_amount'],
                'updated_by' => Auth::id(),
            ]))->save();
            $expense->items()->delete();
            $expense->items()->createMany($items);

            if (in_array($data['status'], ['approved', 'posted'], true)) {
                $journal = $this->posting->postExpenseVoucher($expense->fresh('items'));
                if ($journal) $expense->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            }

            return $expense->fresh(['items.category', 'category', 'account', 'paidFromAccount', 'supplier', 'journal']);
        });
    }

    public function postExpense(int $id): ExpenseVoucher
    {
        return DB::transaction(function () use ($id) {
            $expense = ExpenseVoucher::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($id);
            $journal = $this->posting->postExpenseVoucher($expense);
            if ($journal) $expense->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            return $expense->fresh(['journal', 'items']);
        });
    }

    public function reverseExpense(int $id, string $remarks): ExpenseVoucher
    {
        return DB::transaction(function () use ($id, $remarks) {
            $expense = ExpenseVoucher::query()->where('business_id', AppController::businessId())->with('journal.entries')->findOrFail($id);
            if (!$expense->journal) throw ValidationException::withMessages(['journal' => 'Expense is not posted.']);
            $this->posting->reverseJournalVoucher($expense->journal, $remarks);
            $expense->update(['status' => 'reversed', 'cancelled_by' => Auth::id(), 'cancelled_at' => now()]);
            return $expense->fresh('journal');
        });
    }

    public function otherIncome(array $filters)
    {
        return OtherIncomeVoucher::query()->with(['category', 'receivedIntoAccount', 'customer', 'branch'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $q->where(function (Builder $inner) use ($filters) {
                    $inner->where('voucher_number', 'like', '%' . $filters['search'] . '%')->orWhere('party_name', 'like', '%' . $filters['search'] . '%')->orWhere('reference_number', 'like', '%' . $filters['search'] . '%');
                });
            })
            ->when(!empty($filters['category_id']), fn (Builder $q) => $q->where('income_category_id', $filters['category_id']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->latest('id')->paginate(20);
    }

    public function saveOtherIncome(array $data, ?int $id = null): OtherIncomeVoucher
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $income = $id ? OtherIncomeVoucher::query()->where('business_id', $businessId)->findOrFail($id) : new OtherIncomeVoucher(['business_id' => $businessId, 'created_by' => Auth::id(), 'voucher_number' => $this->nextNumber('OIN', OtherIncomeVoucher::class, 'voucher_number')]);
            if (in_array($income->status, ['approved', 'posted', 'reversed'], true)) throw ValidationException::withMessages(['status' => 'Posted income cannot be directly edited.']);
            $this->assertIncomeCategory($data['income_category_id']);
            $this->assertAccount($data['income_account_id'], ['income']);
            $this->assertAccount($data['received_into_account_id'], ['cash', 'bank']);
            $data = $this->calculateIncomeTotals($data);
            $income->fill($data)->save();
            if (in_array($data['status'], ['approved', 'posted'], true)) {
                $journal = $this->posting->postOtherIncomeVoucher($income);
                if ($journal) $income->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            }
            return $income->fresh(['category', 'account', 'receivedIntoAccount', 'customer', 'journal']);
        });
    }

    public function postOtherIncome(int $id): OtherIncomeVoucher
    {
        return DB::transaction(function () use ($id) {
            $income = OtherIncomeVoucher::query()->where('business_id', AppController::businessId())->findOrFail($id);
            $journal = $this->posting->postOtherIncomeVoucher($income);
            if ($journal) $income->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            return $income->fresh('journal');
        });
    }

    public function reverseOtherIncome(int $id, string $remarks): OtherIncomeVoucher
    {
        return DB::transaction(function () use ($id, $remarks) {
            $income = OtherIncomeVoucher::query()->where('business_id', AppController::businessId())->with('journal.entries')->findOrFail($id);
            if (!$income->journal) throw ValidationException::withMessages(['journal' => 'Other income is not posted.']);
            $this->posting->reverseJournalVoucher($income->journal, $remarks);
            $income->update(['status' => 'reversed']);
            return $income->fresh('journal');
        });
    }

    public function recurring(array $filters)
    {
        return RecurringExpenseTemplate::query()->with(['category', 'paidFromAccount'])->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function saveRecurring(array $data, ?int $id = null): RecurringExpenseTemplate
    {
        $businessId = AppController::businessId();
        $this->assertExpenseCategory($data['expense_category_id']);
        $this->assertAccount($data['expense_account_id'], ['expense']);
        if (!empty($data['paid_from_account_id'])) $this->assertAccount($data['paid_from_account_id'], ['cash', 'bank']);
        $template = $id ? RecurringExpenseTemplate::query()->where('business_id', $businessId)->findOrFail($id) : new RecurringExpenseTemplate(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $template->fill($data)->save();
        return $template->fresh(['category', 'paidFromAccount']);
    }

    public function pettyCash(array $filters)
    {
        return PettyCashAdvance::query()->with(['cashAccount', 'branch'])->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function savePettyCash(array $data, ?int $id = null): PettyCashAdvance
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->assertAccount($data['cash_account_id'], ['cash']);
            $advance = $id ? PettyCashAdvance::query()->where('business_id', $businessId)->findOrFail($id) : new PettyCashAdvance(['business_id' => $businessId, 'created_by' => Auth::id(), 'voucher_number' => $this->nextNumber('PC', PettyCashAdvance::class, 'voucher_number')]);
            if (!in_array($advance->status ?: 'draft', ['draft'], true)) throw ValidationException::withMessages(['status' => 'Issued petty cash cannot be directly edited.']);
            $advance->fill(array_merge($data, ['balance_amount' => $data['amount'], 'settled_amount' => 0]))->save();
            if ($data['status'] === 'issued') {
                $journal = $this->posting->postPettyCashAdvance($advance);
                if ($journal) $advance->update(['journal_voucher_id' => $journal->id, 'approved_by' => Auth::id(), 'status' => 'issued']);
            }
            return $advance->fresh(['cashAccount', 'journal']);
        });
    }

    public function statementImports(array $filters)
    {
        return BankStatementImport::query()->with('bankAccount')->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function importStatement(array $data): BankStatementImport
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $this->assertAccount($data['bank_account_id'], ['bank']);
            $import = BankStatementImport::query()->create([
                'business_id' => $businessId, 'bank_account_id' => $data['bank_account_id'], 'file_name' => $data['file_name'],
                'statement_start_date' => $data['statement_start_date'] ?? null, 'statement_end_date' => $data['statement_end_date'] ?? null,
                'opening_balance' => $data['opening_balance'] ?? null, 'closing_balance' => $data['closing_balance'] ?? null,
                'total_rows' => count($data['lines']), 'status' => 'imported', 'imported_by' => Auth::id(),
            ]);
            $duplicates = 0; $imported = 0;
            foreach ($data['lines'] as $line) {
                $exists = BankStatementLine::query()->where('business_id', $businessId)->where('bank_account_id', $data['bank_account_id'])
                    ->whereDate('transaction_date', $line['transaction_date'])
                    ->where('reference_number', $line['reference_number'] ?? null)
                    ->where('external_transaction_id', $line['external_transaction_id'] ?? null)->exists();
                if ($exists) { $duplicates++; continue; }
                BankStatementLine::query()->create(array_merge($line, ['business_id' => $businessId, 'bank_account_id' => $data['bank_account_id'], 'bank_statement_import_id' => $import->id]));
                $imported++;
            }
            $import->update(['imported_rows' => $imported, 'duplicate_rows' => $duplicates]);
            return $import->fresh(['bankAccount', 'lines']);
        });
    }

    public function statementLines(array $filters)
    {
        return BankStatementLine::query()->where('business_id', AppController::businessId())
            ->when(!empty($filters['bank_account_id']), fn (Builder $q) => $q->where('bank_account_id', $filters['bank_account_id']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('reconciliation_status', $filters['status']))
            ->latest('transaction_date')->paginate(50);
    }

    public function ledgerEntriesForBank(array $filters)
    {
        $businessId = AppController::businessId();
        return JournalEntry::query()->with(['account', 'voucher'])->where('business_id', $businessId)
            ->where('account_id', $filters['bank_account_id'])
            ->whereDoesntHave('bankReconciliationItems')
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->latest('id')->limit(100)->get();
    }

    public function reconciliations(array $filters)
    {
        return BankReconciliation::query()->with('bankAccount')->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function saveReconciliation(array $data): BankReconciliation
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $bank = $this->assertAccount($data['bank_account_id'], ['bank']);
            $ledgerBalance = (float) $bank->current_balance;
            $reconciliation = BankReconciliation::query()->create([
                'business_id' => $businessId, 'bank_account_id' => $bank->id, 'statement_start_date' => $data['statement_start_date'] ?? null,
                'statement_end_date' => $data['statement_end_date'] ?? null, 'statement_closing_balance' => $data['statement_closing_balance'],
                'ledger_closing_balance' => $ledgerBalance, 'difference_amount' => round($ledgerBalance - (float) $data['statement_closing_balance'], 2),
                'status' => 'draft', 'created_by' => Auth::id(),
            ]);
            foreach ($data['items'] ?? [] as $item) {
                $line = !empty($item['bank_statement_line_id']) ? BankStatementLine::query()->where('business_id', $businessId)->findOrFail($item['bank_statement_line_id']) : null;
                $entry = !empty($item['journal_entry_id']) ? JournalEntry::query()->where('business_id', $businessId)->findOrFail($item['journal_entry_id']) : null;
                if ($line && $line->reconciliation_status === 'matched') throw ValidationException::withMessages(['items' => 'A statement line is already matched.']);
                if ($entry && (int) $entry->account_id !== (int) $bank->id) throw ValidationException::withMessages(['items' => 'Ledger entry must belong to selected bank account.']);
                $reconciliation->items()->create(array_merge($item, ['business_id' => $businessId, 'status' => 'matched', 'created_by' => Auth::id()]));
                if ($line) $line->update(['reconciliation_status' => 'matched', 'matched_journal_entry_id' => $entry ? $entry->id : null, 'matched_at' => now(), 'matched_by' => Auth::id()]);
            }
            return $reconciliation->fresh(['items.statementLine', 'items.journalEntry', 'bankAccount']);
        });
    }

    public function reports(array $filters): array
    {
        $businessId = AppController::businessId();
        $expense = ExpenseVoucher::query()->where('business_id', $businessId)->whereIn('status', ['posted', 'approved']);
        $income = OtherIncomeVoucher::query()->where('business_id', $businessId)->whereIn('status', ['posted', 'approved']);
        foreach (['date_from' => '>=', 'date_to' => '<='] as $key => $op) {
            if (!empty($filters[$key])) {
                $expense->whereDate('expense_date', $op, $filters[$key]);
                $income->whereDate('income_date', $op, $filters[$key]);
            }
        }
        $expenseTax = (clone $expense)->selectRaw('COALESCE(SUM(cgst_amount + sgst_amount + igst_amount + cess_amount), 0) as total')->value('total');
        $incomeTax = (clone $income)->selectRaw('COALESCE(SUM(cgst_amount + sgst_amount + igst_amount + cess_amount), 0) as total')->value('total');
        return [
            'expense_summary' => ['total' => (float) (clone $expense)->sum('total_amount'), 'taxable' => (float) (clone $expense)->sum('taxable_amount'), 'tax' => (float) $expenseTax, 'unpaid' => (float) ExpenseVoucher::query()->where('business_id', $businessId)->where('payment_status', 'unpaid')->sum('total_amount')],
            'income_summary' => ['total' => (float) (clone $income)->sum('total_amount'), 'taxable' => (float) (clone $income)->sum('taxable_amount'), 'tax' => (float) $incomeTax],
        ];
    }

    private function calculateExpenseItems(array $rows, string $taxType): array
    {
        $items = [];
        foreach ($rows as $row) {
            $this->assertExpenseCategory($row['expense_category_id']);
            $this->assertAccount($row['expense_account_id'], ['expense']);
            $qty = (float) $row['quantity'];
            $rate = (float) $row['rate'];
            $discount = (float) ($row['discount_amount'] ?? 0);
            $gstRate = (float) ($row['gst_rate'] ?? 0);
            $base = max(0, round(($qty * $rate) - $discount, 2));
            $taxable = $taxType === 'non_taxable' ? 0 : $base;
            $gst = round($taxable * $gstRate / 100, 2);
            $cgst = $taxType === 'exclusive' ? round($gst / 2, 2) : (float) ($row['cgst_amount'] ?? 0);
            $sgst = $taxType === 'exclusive' ? round($gst / 2, 2) : (float) ($row['sgst_amount'] ?? 0);
            $igst = (float) ($row['igst_amount'] ?? 0);
            $cess = (float) ($row['cess_amount'] ?? 0);
            $items[] = [
                'expense_category_id' => $row['expense_category_id'], 'expense_account_id' => $row['expense_account_id'],
                'description' => $row['description'], 'hsn_sac_code' => $row['hsn_sac_code'] ?? null, 'quantity' => $qty, 'rate' => $rate,
                'discount_amount' => $discount, 'taxable_amount' => $taxable, 'gst_rate' => $gstRate, 'cgst_amount' => $cgst,
                'sgst_amount' => $sgst, 'igst_amount' => $igst, 'cess_amount' => $cess, 'line_total' => round($base + $cgst + $sgst + $igst + $cess, 2),
                'cost_center_id' => $row['cost_center_id'] ?? null, 'notes' => $row['notes'] ?? null,
            ];
        }
        return $items;
    }

    private function expenseTotals(array $items, bool $tdsApplicable, float $tdsRate): array
    {
        $taxable = collect($items)->sum('taxable_amount');
        $cgst = collect($items)->sum('cgst_amount');
        $sgst = collect($items)->sum('sgst_amount');
        $igst = collect($items)->sum('igst_amount');
        $cess = collect($items)->sum('cess_amount');
        $lineTotal = collect($items)->sum('line_total');
        $nonTaxable = $lineTotal - $taxable - $cgst - $sgst - $igst - $cess;
        $tds = $tdsApplicable ? round($taxable * $tdsRate / 100, 2) : 0;
        if ($tds > $taxable) throw ValidationException::withMessages(['tds_amount' => 'TDS amount cannot exceed taxable amount.']);
        return [
            'taxable_amount' => round($taxable, 2), 'cgst_amount' => round($cgst, 2), 'sgst_amount' => round($sgst, 2),
            'igst_amount' => round($igst, 2), 'cess_amount' => round($cess, 2), 'non_taxable_amount' => round(max(0, $nonTaxable), 2),
            'total_amount' => round($lineTotal, 2), 'tds_amount' => $tds, 'net_paid_amount' => round($lineTotal - $tds, 2),
        ];
    }

    private function calculateIncomeTotals(array $data): array
    {
        $total = (float) $data['total_amount'];
        $tax = (float) ($data['cgst_amount'] ?? 0) + (float) ($data['sgst_amount'] ?? 0) + (float) ($data['igst_amount'] ?? 0) + (float) ($data['cess_amount'] ?? 0);
        $data['taxable_amount'] = round((float) ($data['taxable_amount'] ?? max(0, $total - $tax - (float) ($data['non_taxable_amount'] ?? 0))), 2);
        $data['non_taxable_amount'] = round((float) ($data['non_taxable_amount'] ?? 0), 2);
        $data['cgst_amount'] = round((float) ($data['cgst_amount'] ?? 0), 2);
        $data['sgst_amount'] = round((float) ($data['sgst_amount'] ?? 0), 2);
        $data['igst_amount'] = round((float) ($data['igst_amount'] ?? 0), 2);
        $data['cess_amount'] = round((float) ($data['cess_amount'] ?? 0), 2);
        return $data;
    }

    private function assertAccount(int $id, array $types): Account
    {
        $account = Account::query()->where('business_id', AppController::businessId())->whereIn('account_type', $types)->findOrFail($id);
        if ($account->status !== 'active') throw ValidationException::withMessages(['account_id' => 'Selected account is inactive.']);
        return $account;
    }

    private function assertExpenseCategory(int $id): ExpenseCategory
    {
        return ExpenseCategory::query()->where('business_id', AppController::businessId())->findOrFail($id);
    }

    private function assertIncomeCategory(int $id): IncomeCategory
    {
        return IncomeCategory::query()->where('business_id', AppController::businessId())->findOrFail($id);
    }

    private function nextNumber(string $prefix, string $model, string $column): string
    {
        $businessId = AppController::businessId();
        $prefix .= '-' . date('Y') . '-';
        $last = $model::query()->where('business_id', $businessId)->where($column, 'like', $prefix . '%')->lockForUpdate()->orderByDesc('id')->value($column);
        return $prefix . str_pad((string) ($last ? ((int) substr($last, strlen($prefix)) + 1) : 1), 5, '0', STR_PAD_LEFT);
    }
}
