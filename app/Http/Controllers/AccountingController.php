<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContraVoucherRequest;
use App\Http\Requests\BankReconciliationRequest;
use App\Http\Requests\BankStatementImportRequest;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Http\Requests\ExpenseVoucherRequest;
use App\Http\Requests\IncomeCategoryRequest;
use App\Http\Requests\JournalVoucherRequest;
use App\Http\Requests\OpeningStockReverseRequest;
use App\Http\Requests\PaymentVoucherRequest;
use App\Http\Requests\PettyCashAdvanceRequest;
use App\Http\Requests\ReceiptVoucherRequest;
use App\Http\Requests\RecurringExpenseTemplateRequest;
use App\Http\Requests\OtherIncomeVoucherRequest;
use App\Models\Account;
use App\Services\AccountingService;
use App\Services\ExpenseIncomeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccountingController extends Controller
{
    private AccountingService $accounting;
    private ExpenseIncomeService $expenseIncome;

    public function __construct(AccountingService $accounting, ExpenseIncomeService $expenseIncome)
    {
        $this->accounting = $accounting;
        $this->expenseIncome = $expenseIncome;
    }

    public function chartOfAccounts()
    {
        if ($redirect = AppController::guardPage('accounts')) return $redirect;
        return Inertia::render('Accounting/Accounts', ['page' => 'accounts', 'title' => 'Chart of Accounts', 'role_id' => AppController::roleId()]);
    }

    public function vouchers()
    {
        if ($redirect = AppController::guardPage('vouchers')) return $redirect;
        return Inertia::render('Accounting/Vouchers', ['page' => 'vouchers', 'title' => 'Accounting Vouchers', 'role_id' => AppController::roleId()]);
    }

    public function ledgers()
    {
        if ($redirect = AppController::guardPage('ledgers')) return $redirect;
        return Inertia::render('Accounting/Ledgers', ['page' => 'ledgers', 'title' => 'Ledgers and Books', 'role_id' => AppController::roleId()]);
    }

    public function references()
    {
        abort_unless(AppController::canOpen('accounts') || AppController::canOpen('vouchers') || AppController::canOpen('ledgers'), 403);
        return response()->json($this->accounting->references());
    }

    public function accounts(Request $request)
    {
        abort_unless(AppController::canOpen('accounts'), 403);
        $paginator = $this->accounting->chart($request->all());
        return response()->json(['accounts' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveAccount(Request $request, ?int $account = null)
    {
        abort_unless(AppController::canOpen('accounts'), 403);
        $data = $request->validate([
            'account_group_id' => ['required', 'integer'], 'parent_account_id' => ['nullable', 'integer'],
            'account_code' => ['required', 'string', 'max:50'], 'account_name' => ['required', 'string', 'max:255'],
            'account_type' => ['required', 'string', 'max:40'], 'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => ['nullable', 'in:debit,credit'], 'is_reconciliation_enabled' => ['nullable', 'boolean'], 'status' => ['required', 'in:active,inactive'],
            'branch_id' => ['nullable', 'integer'], 'bank_name' => ['nullable', 'string', 'max:255'],
            'account_holder_name' => ['nullable', 'string', 'max:255'], 'account_number' => ['nullable', 'string', 'max:255'],
            'bank_account_type' => ['nullable', 'string', 'max:40'], 'ifsc_code' => ['nullable', 'string', 'max:30'],
            'bank_branch_name' => ['nullable', 'string', 'max:255'], 'upi_id' => ['nullable', 'string', 'max:255'],
            'swift_code' => ['nullable', 'string', 'max:40'],
        ]);
        return response()->json(['message' => 'Account saved successfully.', 'account' => $this->accounting->saveAccount($data, $account)]);
    }

    public function settings(Request $request)
    {
        abort_unless(AppController::canOpen('accounts'), 403);
        return response()->json(['message' => 'Account settings saved.', 'settings' => $this->accounting->updateSettings($request->all())]);
    }

    public function journalList(Request $request)
    {
        abort_unless(AppController::canOpen('vouchers'), 403);
        $paginator = $this->accounting->journals($request->all());
        return response()->json(['journals' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function journalStore(JournalVoucherRequest $request)
    {
        return response()->json(['message' => 'Journal voucher saved.', 'journal' => $this->accounting->createJournal($request->validated())], 201);
    }

    public function journalApprove(int $journal)
    {
        return response()->json(['message' => 'Journal voucher approved.', 'journal' => $this->accounting->approveJournal($journal)]);
    }

    public function journalReverse(OpeningStockReverseRequest $request, int $journal)
    {
        return response()->json(['message' => 'Journal voucher reversed.', 'journal' => $this->accounting->reverseJournal($journal, $request->validated()['remarks'])]);
    }

    public function receipts(Request $request)
    {
        $paginator = $this->accounting->receipts($request->all());
        return response()->json(['receipts' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function receiptStore(ReceiptVoucherRequest $request)
    {
        return response()->json(['message' => 'Receipt saved.', 'receipt' => $this->accounting->createReceipt($request->validated())], 201);
    }

    public function payments(Request $request)
    {
        $paginator = $this->accounting->payments($request->all());
        return response()->json(['payments' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function paymentStore(PaymentVoucherRequest $request)
    {
        return response()->json(['message' => 'Payment saved.', 'payment' => $this->accounting->createPayment($request->validated())], 201);
    }

    public function contraStore(ContraVoucherRequest $request)
    {
        return response()->json(['message' => 'Contra voucher posted.', 'journal' => $this->accounting->postContra($request->validated())], 201);
    }

    public function ledger(Request $request)
    {
        return response()->json($this->accounting->ledger($request->all()));
    }

    public function cashBankBook(Request $request)
    {
        return response()->json($this->accounting->cashBankBook($request->all()));
    }

    public function customerOutstanding(Request $request)
    {
        return response()->json($this->accounting->customerOutstanding($request->integer('customer_id') ?: null));
    }

    public function supplierOutstanding(Request $request)
    {
        return response()->json($this->accounting->supplierOutstanding($request->integer('supplier_id') ?: null));
    }

    public function expenses()
    {
        if ($redirect = AppController::guardPage('expenses')) return $redirect;
        return Inertia::render('Accounting/Expenses', ['page' => 'expenses', 'title' => 'Expenses, Income and Bank Reconciliation', 'role_id' => AppController::roleId()]);
    }

    public function expenseReferences()
    {
        abort_unless(AppController::canOpen('expenses'), 403);
        return response()->json($this->expenseIncome->references());
    }

    public function expenseCategoryList(Request $request)
    {
        $paginator = $this->expenseIncome->expenseCategories($request->all());
        return response()->json(['categories' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveExpenseCategory(ExpenseCategoryRequest $request, ?int $category = null)
    {
        return response()->json(['message' => 'Expense category saved.', 'category' => $this->expenseIncome->saveExpenseCategory($request->validated(), $category)], $category ? 200 : 201);
    }

    public function deleteExpenseCategory(Request $request, int $category)
    {
        $this->expenseIncome->deleteExpenseCategory($category, $request->boolean('force'));
        return response()->json(['message' => 'Expense category deleted.']);
    }

    public function incomeCategoryList(Request $request)
    {
        $paginator = $this->expenseIncome->incomeCategories($request->all());
        return response()->json(['categories' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function saveIncomeCategory(IncomeCategoryRequest $request, ?int $category = null)
    {
        return response()->json(['message' => 'Income category saved.', 'category' => $this->expenseIncome->saveIncomeCategory($request->validated(), $category)], $category ? 200 : 201);
    }

    public function deleteIncomeCategory(Request $request, int $category)
    {
        $this->expenseIncome->deleteIncomeCategory($category, $request->boolean('force'));
        return response()->json(['message' => 'Income category deleted.']);
    }

    public function expenseList(Request $request)
    {
        $paginator = $this->expenseIncome->expenses($request->all());
        return response()->json(['expenses' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function expenseStore(ExpenseVoucherRequest $request)
    {
        return response()->json(['message' => 'Expense saved.', 'expense' => $this->expenseIncome->saveExpense($request->validated())], 201);
    }

    public function expenseUpdate(ExpenseVoucherRequest $request, int $expense)
    {
        return response()->json(['message' => 'Expense updated.', 'expense' => $this->expenseIncome->saveExpense($request->validated(), $expense)]);
    }

    public function expensePost(int $expense)
    {
        return response()->json(['message' => 'Expense posted.', 'expense' => $this->expenseIncome->postExpense($expense)]);
    }

    public function expenseReverse(OpeningStockReverseRequest $request, int $expense)
    {
        return response()->json(['message' => 'Expense reversed.', 'expense' => $this->expenseIncome->reverseExpense($expense, $request->validated()['remarks'])]);
    }

    public function otherIncomeList(Request $request)
    {
        $paginator = $this->expenseIncome->otherIncome($request->all());
        return response()->json(['income' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function otherIncomeStore(OtherIncomeVoucherRequest $request)
    {
        return response()->json(['message' => 'Other income saved.', 'income' => $this->expenseIncome->saveOtherIncome($request->validated())], 201);
    }

    public function otherIncomeUpdate(OtherIncomeVoucherRequest $request, int $income)
    {
        return response()->json(['message' => 'Other income updated.', 'income' => $this->expenseIncome->saveOtherIncome($request->validated(), $income)]);
    }

    public function otherIncomePost(int $income)
    {
        return response()->json(['message' => 'Other income posted.', 'income' => $this->expenseIncome->postOtherIncome($income)]);
    }

    public function otherIncomeReverse(OpeningStockReverseRequest $request, int $income)
    {
        return response()->json(['message' => 'Other income reversed.', 'income' => $this->expenseIncome->reverseOtherIncome($income, $request->validated()['remarks'])]);
    }

    public function recurringList(Request $request)
    {
        $paginator = $this->expenseIncome->recurring($request->all());
        return response()->json(['recurring' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function recurringStore(RecurringExpenseTemplateRequest $request)
    {
        return response()->json(['message' => 'Recurring expense template saved.', 'template' => $this->expenseIncome->saveRecurring($request->validated())], 201);
    }

    public function recurringUpdate(RecurringExpenseTemplateRequest $request, int $template)
    {
        return response()->json(['message' => 'Recurring expense template updated.', 'template' => $this->expenseIncome->saveRecurring($request->validated(), $template)]);
    }

    public function pettyCashList(Request $request)
    {
        $paginator = $this->expenseIncome->pettyCash($request->all());
        return response()->json(['advances' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function pettyCashStore(PettyCashAdvanceRequest $request)
    {
        return response()->json(['message' => 'Petty cash advance saved.', 'advance' => $this->expenseIncome->savePettyCash($request->validated())], 201);
    }

    public function bankImportList(Request $request)
    {
        $paginator = $this->expenseIncome->statementImports($request->all());
        return response()->json(['imports' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function bankImportStore(BankStatementImportRequest $request)
    {
        return response()->json(['message' => 'Bank statement imported.', 'import' => $this->expenseIncome->importStatement($request->validated())], 201);
    }

    public function bankStatementLines(Request $request)
    {
        $paginator = $this->expenseIncome->statementLines($request->all());
        return response()->json(['lines' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function bankLedgerEntries(Request $request)
    {
        return response()->json($this->expenseIncome->ledgerEntriesForBank($request->all()));
    }

    public function reconciliationList(Request $request)
    {
        $paginator = $this->expenseIncome->reconciliations($request->all());
        return response()->json(['reconciliations' => $paginator->getCollection()->values(), 'pagination' => $this->pagination($paginator)]);
    }

    public function reconciliationStore(BankReconciliationRequest $request)
    {
        return response()->json(['message' => 'Bank reconciliation saved.', 'reconciliation' => $this->expenseIncome->saveReconciliation($request->validated())], 201);
    }

    public function expenseReports(Request $request)
    {
        return response()->json($this->expenseIncome->reports($request->all()));
    }
    public function gst() { return ModuleController::render('gst', 'GST'); }
    public function gstReturns() { return ModuleController::render('inventory-gst-returns', 'GST Returns'); }

    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()];
    }
}
