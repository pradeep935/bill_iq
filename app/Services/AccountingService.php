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
use App\Models\SalesVoucher;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            $receipt = ReceiptVoucher::query()->create(array_merge($data, [
                'business_id' => $businessId, 'voucher_number' => $this->nextNumber('RCPT'), 'status' => 'draft', 'created_by' => Auth::id(),
            ]));
            if ($data['status'] === 'approved') $this->posting->postCustomerReceipt($receipt, $data['allocations'] ?? []);
            return $receipt->fresh(['customer', 'journal']);
        });
    }

    public function createPayment(array $data): PaymentVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $payment = PaymentVoucher::query()->create(array_merge($data, [
                'business_id' => $businessId, 'voucher_number' => $this->nextNumber('PAY'), 'status' => 'draft', 'created_by' => Auth::id(),
            ]));
            if ($data['status'] === 'approved') $this->posting->postSupplierPayment($payment, $data['allocations'] ?? []);
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
            ->whereIn('journal_vouchers.status', ['posted', 'approved'])
            ->when(!empty($filters['account_id']), fn ($q) => $q->where('journal_entries.account_id', $filters['account_id']))
            ->when(!empty($filters['customer_id']), fn ($q) => $q->where('journal_entries.customer_id', $filters['customer_id']))
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

    public function customerOutstanding(?int $customerId = null)
    {
        $businessId = AppController::businessId();
        return SalesVoucher::query()->where('business_id', $businessId)->whereIn('status', ['approved', 'confirmed'])->when($customerId, fn ($q) => $q->where('customer_id', $customerId))->get()->map(function ($invoice) {
            $allocated = DB::table('ledger_allocations')->where('business_id', $invoice->business_id)->where('reference_type', SalesVoucher::class)->where('reference_id', $invoice->id)->sum('allocated_amount');
            return ['customer_id' => $invoice->customer_id, 'invoice_number' => $invoice->invoice_number, 'invoice_date' => optional($invoice->invoice_date)->format('Y-m-d'), 'due_date' => optional($invoice->due_date)->format('Y-m-d'), 'invoice_amount' => (float) $invoice->grand_total, 'received_amount' => (float) $allocated, 'outstanding_amount' => round((float) $invoice->grand_total - (float) $allocated, 2), 'ageing_days' => now()->diffInDays($invoice->due_date ?: $invoice->invoice_date, false) * -1];
        });
    }

    public function supplierOutstanding(?int $supplierId = null)
    {
        $businessId = AppController::businessId();
        return PurchaseVoucher::query()->where('business_id', $businessId)->whereIn('status', ['approved', 'confirmed'])->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get()->map(function ($purchase) {
            $allocated = DB::table('ledger_allocations')->where('business_id', $purchase->business_id)->where('reference_type', PurchaseVoucher::class)->where('reference_id', $purchase->id)->sum('allocated_amount');
            return ['supplier_id' => $purchase->supplier_id, 'purchase_number' => $purchase->voucher_number, 'purchase_date' => optional($purchase->purchase_date)->format('Y-m-d'), 'due_date' => optional($purchase->due_date)->format('Y-m-d'), 'purchase_amount' => (float) $purchase->grand_total, 'paid_amount' => (float) $allocated, 'outstanding_amount' => round((float) $purchase->grand_total - (float) $allocated, 2), 'ageing_days' => now()->diffInDays($purchase->due_date ?: $purchase->purchase_date, false) * -1];
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
