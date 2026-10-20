<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Branch;
use App\Models\BusinessAccountSetting;
use App\Models\Customer;
use App\Models\JournalVoucher;
use App\Models\PaymentVoucher;
use App\Models\PurchaseVoucher;
use App\Models\ReceiptVoucher;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountingService
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
            'accounts' => Account::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('account_name')->get(),
            'cash_bank_accounts' => Account::query()->where('business_id', $businessId)->whereIn('account_type', ['cash', 'bank'])->where('status', 'active')->orderBy('account_name')->get(),
            'groups' => AccountGroup::query()->where('business_id', $businessId)->orderBy('group_name')->get(),
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'customers' => Customer::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('customer_name')->limit(200)->get(),
            'suppliers' => Supplier::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('supplier_name')->limit(200)->get(),
            'settings' => BusinessAccountSetting::query()->where('business_id', $businessId)->first(),
        ];
    }

    public function chart(array $filters = [])
    {
        $businessId = AppController::businessId();
        return Account::query()->with('group')->where('business_id', $businessId)
            ->when(!empty($filters['type']), fn (Builder $q) => $q->where('account_type', $filters['type']))
            ->when(!empty($filters['search']), fn (Builder $q) => $q->where(function (Builder $inner) use ($filters) {
                $inner->where('account_name', 'like', '%' . $filters['search'] . '%')->orWhere('account_code', 'like', '%' . $filters['search'] . '%');
            }))
            ->orderBy('account_code')->paginate(min(max((int) ($filters['per_page'] ?? 50), 1), 100));
    }

    public function saveAccount(array $data, ?int $id = null): Account
    {
        $businessId = AppController::businessId();
        $this->validateBranchOwnership($businessId, $data['branch_id'] ?? null);
        $account = $id ? Account::query()->where('business_id', $businessId)->findOrFail($id) : new Account(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $account->fill([
            'account_group_id' => $data['account_group_id'], 'parent_account_id' => $data['parent_account_id'] ?? null,
            'account_code' => $data['account_code'], 'code' => $data['account_code'], 'account_name' => $data['account_name'], 'name' => $data['account_name'],
            'account_type' => $data['account_type'], 'opening_balance' => $data['opening_balance'] ?? 0,
            'opening_balance_type' => $data['opening_balance_type'] ?? null, 'is_reconciliation_enabled' => (bool) ($data['is_reconciliation_enabled'] ?? false),
            'branch_id' => $data['branch_id'] ?? null, 'bank_name' => $data['bank_name'] ?? null,
            'account_holder_name' => $data['account_holder_name'] ?? null, 'account_number' => $data['account_number'] ?? null,
            'bank_account_type' => $data['bank_account_type'] ?? null, 'ifsc_code' => $data['ifsc_code'] ?? null,
            'bank_branch_name' => $data['bank_branch_name'] ?? null, 'upi_id' => $data['upi_id'] ?? null,
            'swift_code' => $data['swift_code'] ?? null,
            'status' => $data['status'] ?? 'active', 'updated_by' => Auth::id(),
        ])->save();
        return $account->fresh('group');
    }

    private function validateBranchOwnership(int $businessId, $branchId): void
    {
        if (!$branchId || !Schema::hasTable('branches')) {
            return;
        }

        if (!DB::table('branches')->where('business_id', $businessId)->where('id', $branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'Selected branch does not belong to the current company.']);
        }
    }

    public function updateSettings(array $data): BusinessAccountSetting
    {
        $allowed = [
            'cash_account_id', 'default_bank_account_id', 'accounts_receivable_id', 'accounts_payable_id',
            'sales_account_id', 'purchase_account_id', 'sales_return_account_id', 'purchase_return_account_id',
            'inventory_account_id', 'cost_of_goods_sold_account_id', 'output_cgst_account_id', 'output_sgst_account_id',
            'output_igst_account_id', 'input_cgst_account_id', 'input_sgst_account_id', 'input_igst_account_id',
            'output_cess_account_id', 'input_cess_account_id', 'discount_allowed_account_id',
            'discount_received_account_id', 'round_off_account_id', 'shipping_income_account_id',
            'shipping_expense_account_id', 'customer_advance_account_id', 'supplier_advance_account_id',
            'tds_payable_account_id', 'employee_reimbursement_payable_account_id', 'petty_cash_advance_account_id',
            'bank_charges_account_id', 'interest_income_account_id', 'interest_expense_account_id',
            'payment_gateway_charges_account_id', 'payment_gateway_clearing_account_id',
            'salary_expense_account_id', 'salary_payable_account_id', 'pf_payable_account_id',
            'esi_payable_account_id', 'professional_tax_payable_account_id', 'salary_tds_payable_account_id',
            'payroll_round_off_account_id', 'employee_advance_account_id', 'employee_loan_account_id',
            'reimbursement_payable_account_id', 'bonus_expense_account_id', 'gratuity_expense_account_id',
        ];

        return BusinessAccountSetting::query()->updateOrCreate(
            ['business_id' => AppController::businessId()],
            array_intersect_key($data, array_flip($allowed))
        );
    }

    public function journals(array $filters = [])
    {
        return JournalVoucher::query()->with(['entries.account', 'creator'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['voucher_type']), fn (Builder $q) => $q->where('voucher_type', $filters['voucher_type']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('voucher_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('voucher_date', '<=', $filters['date_to']))
            ->latest('id')->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    public function createJournal(array $data): JournalVoucher
    {
        return $this->posting->createJournalVoucher(array_merge($data, ['status' => $data['status']]));
    }

    public function approveJournal(int $id): JournalVoucher
    {
        $voucher = JournalVoucher::query()->where('business_id', AppController::businessId())->with('entries.account')->findOrFail($id);
        return $this->posting->postJournalVoucher($voucher);
    }

    public function reverseJournal(int $id, string $remarks): JournalVoucher
    {
        $voucher = JournalVoucher::query()->where('business_id', AppController::businessId())->with('entries.account')->findOrFail($id);
        return $this->posting->reverseJournalVoucher($voucher, $remarks);
    }

    public function createReceipt(array $data): ReceiptVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $fail = fn ($field, $message) => throw \Illuminate\Validation\ValidationException::withMessages([$field => $message]);
            if (!Customer::query()->where('business_id', $businessId)->whereKey($data['customer_id'])->exists()) $fail('customer_id', 'Select a customer from the current business.');
            if (!Account::query()->where('business_id', $businessId)->whereKey($data['cash_bank_account_id'])->whereIn('account_type', ['cash', 'bank'])->where('status', 'active')->exists()) $fail('cash_bank_account_id', 'Select an active cash/bank account from the current business.');
            if (!empty($data['branch_id']) && !Branch::query()->where('business_id', $businessId)->whereKey($data['branch_id'])->exists()) $fail('branch_id', 'Invalid branch.');
            if (($data['discount_allowed'] ?? 0) != 0 || ($data['write_off_amount'] ?? 0) != 0) $fail('amount', 'Bill receipts currently support receipt amount only; discount/write-off adjustments are not supported.');
            $allocations = $data['allocations'] ?? [];
            unset($data['allocations']);
            if (!$allocations) $fail('allocations', 'Allocate the receipt to at least one open sales invoice.');
            $ids = array_column($allocations, 'reference_id');
            if (count(array_unique($ids)) !== count($ids)) $fail('allocations', 'An invoice can appear only once.');
            // Lock in a stable order so concurrent receipts cannot settle the same balance twice.
            $invoices = SalesVoucher::query()->where('business_id', $businessId)->where('customer_id', $data['customer_id'])
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sourceBranches = $invoices->map(fn ($invoice) => $invoice->branch_id ? (int) $invoice->branch_id : null)->unique()->values();
            if ($sourceBranches->count() > 1) $fail('branch_id', 'Allocate bills from one branch per receipt. Create separate receipts for different branches.');
            $sourceBranch = $sourceBranches->first();
            if (!empty($data['branch_id']) && (int) $data['branch_id'] !== $sourceBranch) $fail('branch_id', 'Receipt branch must match the allocated sales invoices.');
            if ($sourceBranch && !Branch::query()->where('business_id', $businessId)->whereKey($sourceBranch)->exists()) $fail('branch_id', 'Sales branch does not belong to the current business.');
            $data['branch_id'] = $sourceBranch;
            $total = 0;
            foreach ($allocations as $i => &$allocation) {
                $invoice = $invoices->get($allocation['reference_id']);
                if (($allocation['reference_type'] ?? '') !== SalesVoucher::class || !$invoice || !in_array($invoice->status, ['approved', 'confirmed'])) $fail("allocations.$i.allocated_amount", 'Select an approved invoice belonging to this customer and business.');
                $already = (float) DB::table('ledger_allocations')->where('business_id', $businessId)->where('reference_type', SalesVoucher::class)->where('reference_id', $invoice->id)->sum('allocated_amount');
                $paid = max($already, (float) $invoice->paid_amount);
                $outstanding = round((float) $invoice->grand_total - $paid - SalesReturnVoucher::invoiceAdjustmentTotal($businessId, $invoice->id), 2);
                $amount = round((float) $allocation['allocated_amount'], 2);
                if ($amount <= 0 || $amount > $outstanding) $fail("allocations.$i.allocated_amount", 'Allocation exceeds the bill outstanding amount of '.number_format(max(0, $outstanding), 2).'.');
                $allocation = ['reference_type' => SalesVoucher::class, 'reference_id' => $invoice->id, 'allocated_amount' => $amount, 'original_amount' => (float) $invoice->grand_total];
                $total += (int) round($amount * 100);
            }
            unset($allocation);
            if ($total !== (int) round((float) $data['amount'] * 100)) $fail('allocations', 'Total allocated must equal the receipt amount. Unallocated advances are not supported in this flow.');
            $receipt = ReceiptVoucher::query()->create(array_merge($data, [
                'business_id' => $businessId, 'voucher_number' => $this->nextNumber('RCPT'), 'status' => 'draft', 'created_by' => Auth::id(),
            ]));
            if ($data['status'] === 'approved') {
                $this->posting->postCustomerReceipt($receipt, $allocations);
                foreach ($allocations as $allocation) {
                    $invoice = $invoices->get($allocation['reference_id']);
                    $paid = max((float) $invoice->paid_amount, (float) DB::table('ledger_allocations')->where('business_id', $businessId)->where('reference_type', SalesVoucher::class)->where('reference_id', $invoice->id)->sum('allocated_amount') - $allocation['allocated_amount']);
                    $paid = round($paid + $allocation['allocated_amount'], 2);
                    $balance = max(0, round((float) $invoice->grand_total - $paid - SalesReturnVoucher::invoiceAdjustmentTotal($businessId, $invoice->id), 2));
                    $invoice->update(['paid_amount' => $paid, 'balance_amount' => $balance, 'payment_status' => $balance > 0 ? 'partial' : 'paid']);
                }
            }
            return $receipt->fresh(['customer', 'journal']);
        });
    }

    public function createPayment(array $data): PaymentVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $fail = fn ($field, $message) => throw \Illuminate\Validation\ValidationException::withMessages([$field => $message]);
            if (!Supplier::query()->where('business_id', $businessId)->whereKey($data['supplier_id'])->exists()) $fail('supplier_id', 'Select a supplier from the current business.');
            if (!Account::query()->where('business_id', $businessId)->whereKey($data['cash_bank_account_id'])->whereIn('account_type', ['cash', 'bank'])->where('status', 'active')->exists()) $fail('cash_bank_account_id', 'Select an active cash/bank account from the current business.');
            if (!empty($data['branch_id']) && !Branch::query()->where('business_id', $businessId)->whereKey($data['branch_id'])->exists()) $fail('branch_id', 'Invalid branch.');
            if (($data['discount_received'] ?? 0) != 0 || ($data['write_off_amount'] ?? 0) != 0) $fail('amount', 'Bill payments currently support payment amount only; discount/write-off adjustments are not supported.');
            $allocations = $data['allocations'] ?? [];
            unset($data['allocations']);
            if (!$allocations) $fail('allocations', 'Allocate the payment to at least one open purchase bill.');
            $ids = array_column($allocations, 'reference_id');
            if (count(array_unique($ids)) !== count($ids)) $fail('allocations', 'A purchase bill can appear only once.');
            // Lock in a stable order so concurrent payments cannot settle the same balance twice.
            $purchases = PurchaseVoucher::query()->where('business_id', $businessId)->where('supplier_id', $data['supplier_id'])
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sourceBranches = $purchases->map(fn ($purchase) => $purchase->branch_id ? (int) $purchase->branch_id : null)->unique()->values();
            if ($sourceBranches->count() > 1) $fail('branch_id', 'Allocate bills from one branch per payment. Create separate payments for different branches.');
            $sourceBranch = $sourceBranches->first();
            if (!empty($data['branch_id']) && (int) $data['branch_id'] !== $sourceBranch) $fail('branch_id', 'Payment branch must match the allocated purchase bills.');
            if ($sourceBranch && !Branch::query()->where('business_id', $businessId)->whereKey($sourceBranch)->exists()) $fail('branch_id', 'Purchase branch does not belong to the current business.');
            $data['branch_id'] = $sourceBranch;
            $total = 0;
            foreach ($allocations as $i => &$allocation) {
                $purchase = $purchases->get($allocation['reference_id']);
                if (($allocation['reference_type'] ?? '') !== PurchaseVoucher::class || !$purchase || !in_array($purchase->status, ['approved', 'confirmed'])) $fail("allocations.$i.allocated_amount", 'Select an approved purchase belonging to this supplier and business.');
                $already = (float) DB::table('ledger_allocations')->where('business_id', $businessId)->where('reference_type', PurchaseVoucher::class)->where('reference_id', $purchase->id)->sum('allocated_amount');
                $paid = max($already, (float) $purchase->paid_amount);
                $outstanding = round((float) $purchase->grand_total - $paid, 2);
                $amount = round((float) $allocation['allocated_amount'], 2);
                if ($amount <= 0 || $amount > $outstanding) $fail("allocations.$i.allocated_amount", 'Allocation exceeds the bill outstanding amount of '.number_format(max(0, $outstanding), 2).'.');
                $allocation = ['reference_type' => PurchaseVoucher::class, 'reference_id' => $purchase->id, 'allocated_amount' => $amount, 'original_amount' => (float) $purchase->grand_total];
                $total += (int) round($amount * 100);
            }
            unset($allocation);
            if ($total !== (int) round((float) $data['amount'] * 100)) $fail('allocations', 'Total allocated must equal the payment amount. Unallocated advances are not supported in this flow.');
            $payment = PaymentVoucher::query()->create(array_merge($data, [
                'business_id' => $businessId, 'voucher_number' => $this->nextNumber('PAY'), 'status' => 'draft', 'created_by' => Auth::id(),
            ]));
            if ($data['status'] === 'approved') {
                $this->posting->postSupplierPayment($payment, $allocations);
                foreach ($allocations as $allocation) {
                    $purchase = $purchases->get($allocation['reference_id']);
                    $paid = max((float) $purchase->paid_amount, (float) DB::table('ledger_allocations')->where('business_id', $businessId)->where('reference_type', PurchaseVoucher::class)->where('reference_id', $purchase->id)->sum('allocated_amount') - $allocation['allocated_amount']);
                    $paid = round($paid + $allocation['allocated_amount'], 2);
                    $balance = max(0, round((float) $purchase->grand_total - $paid, 2));
                    $purchase->update(['paid_amount' => $paid, 'balance_amount' => $balance, 'payment_status' => $balance > 0 ? 'partial' : 'paid']);
                }
            }
            return $payment->fresh(['supplier', 'journal']);
        });
    }

    public function receipts(array $filters = [])
    {
        return ReceiptVoucher::query()->with('customer')->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function payments(array $filters = [])
    {
        return PaymentVoucher::query()->with('supplier')->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function postContra(array $data): JournalVoucher
    {
        return $this->posting->postContraVoucher($data);
    }

    public function ledger(array $filters)
    {
        $businessId = AppController::businessId();
        $entries = DB::table('journal_entries')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_entries.journal_voucher_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entries.account_id')
            ->where('journal_entries.business_id', $businessId)
            ->whereIn('journal_vouchers.status', ['posted', 'approved', 'reversed'])
            ->when(!empty($filters['account_id']), fn ($q) => $q->where('journal_entries.account_id', $filters['account_id']))
            ->when(!empty($filters['customer_id']), fn ($q) => $q->where('journal_entries.customer_id', $filters['customer_id']))
            ->when(!empty($filters['customer_id']) && empty($filters['account_id']), fn ($q) => $q->whereIn('journal_entries.account_id', BusinessAccountSetting::query()->where('business_id', $businessId)->select('accounts_receivable_id')))
            ->when(!empty($filters['supplier_id']), fn ($q) => $q->where('journal_entries.supplier_id', $filters['supplier_id']))
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('journal_entries.branch_id', $filters['branch_id']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('journal_vouchers.voucher_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('journal_vouchers.voucher_date', '<=', $filters['date_to']))
            ->orderBy('journal_vouchers.voucher_date')->orderBy('journal_entries.id')
            ->get(['journal_vouchers.voucher_date', 'journal_vouchers.voucher_type', 'journal_vouchers.voucher_number', 'journal_vouchers.reference_number', 'journal_entries.narration', 'accounts.account_name', 'journal_entries.debit_amount', 'journal_entries.credit_amount', 'journal_entries.due_date']);
        $running = 0;
        return $entries->map(function ($row) use (&$running) {
            $running += (float) $row->debit_amount - (float) $row->credit_amount;
            $row->running_balance = round($running, 2);
            return $row;
        });
    }

    public function cashBankBook(array $filters)
    {
        return $this->ledger(['account_id' => $filters['account_id'] ?? null, 'date_from' => $filters['date_from'] ?? null, 'date_to' => $filters['date_to'] ?? null, 'branch_id' => $filters['branch_id'] ?? null]);
    }

    private function ageingDays($date): int
    {
        return $date ? max(0, (int) $date->copy()->startOfDay()->diffInDays(now()->startOfDay(), false)) : 0;
    }

    public function customerOutstanding(?int $customerId = null, ?int $branchId = null)
    {
        $businessId = AppController::businessId();
        return SalesVoucher::query()->where('business_id', $businessId)->whereIn('status', ['approved', 'confirmed'])->when($customerId, fn ($q) => $q->where('customer_id', $customerId))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get()->map(function ($invoice) {
            $allocated = DB::table('ledger_allocations')->where('business_id', $invoice->business_id)->where('reference_type', SalesVoucher::class)->where('reference_id', $invoice->id)->sum('allocated_amount');
            $allocated = max((float) $allocated, (float) $invoice->paid_amount);
            // Credit notes adjusted against this invoice close it; any excess stays as customer credit in the ledger.
            $creditNote = SalesReturnVoucher::invoiceAdjustmentTotal($invoice->business_id, $invoice->id);
            return ['invoice_id' => $invoice->id, 'branch_id' => $invoice->branch_id, 'customer_id' => $invoice->customer_id, 'invoice_number' => $invoice->invoice_number, 'invoice_date' => optional($invoice->invoice_date)->format('Y-m-d'), 'due_date' => optional($invoice->due_date)->format('Y-m-d'), 'invoice_amount' => (float) $invoice->grand_total, 'received_amount' => (float) $allocated, 'credit_note_amount' => $creditNote, 'outstanding_amount' => max(0, round((float) $invoice->grand_total - (float) $allocated - $creditNote, 2)), 'ageing_days' => $this->ageingDays($invoice->due_date ?: $invoice->invoice_date)];
        });
    }

    public function supplierOutstanding(?int $supplierId = null, ?int $branchId = null)
    {
        $businessId = AppController::businessId();
        return PurchaseVoucher::query()->where('business_id', $businessId)->whereIn('status', ['approved', 'confirmed'])->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get()->map(function ($purchase) {
            $allocated = DB::table('ledger_allocations')->where('business_id', $purchase->business_id)->where('reference_type', PurchaseVoucher::class)->where('reference_id', $purchase->id)->sum('allocated_amount');
            $allocated = max((float) $allocated, (float) $purchase->paid_amount);
            return ['purchase_id' => $purchase->id, 'branch_id' => $purchase->branch_id, 'supplier_id' => $purchase->supplier_id, 'purchase_number' => $purchase->voucher_number, 'purchase_date' => optional($purchase->purchase_date)->format('Y-m-d'), 'due_date' => optional($purchase->due_date)->format('Y-m-d'), 'purchase_amount' => (float) $purchase->grand_total, 'paid_amount' => (float) $allocated, 'outstanding_amount' => round((float) $purchase->grand_total - (float) $allocated, 2), 'ageing_days' => $this->ageingDays($purchase->due_date ?: $purchase->purchase_date)];
        });
    }

    private function nextNumber(string $prefix): string
    {
        $businessId = AppController::businessId();
        $prefix .= '-' . date('Y') . '-';
        $lastReceipt = ReceiptVoucher::query()->where('business_id', $businessId)->where('voucher_number', 'like', $prefix . '%')->orderByDesc('id')->value('voucher_number');
        $lastPayment = PaymentVoucher::query()->where('business_id', $businessId)->where('voucher_number', 'like', $prefix . '%')->orderByDesc('id')->value('voucher_number');
        $last = max((int) substr((string) $lastReceipt, strlen($prefix)), (int) substr((string) $lastPayment, strlen($prefix)));
        return $prefix . str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
