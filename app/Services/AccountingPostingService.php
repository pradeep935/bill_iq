<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\BusinessAccountSetting;
use App\Models\ExpenseVoucher;
use App\Models\JournalVoucher;
use App\Models\LedgerAllocation;
use App\Models\OtherIncomeVoucher;
use App\Models\PaymentVoucher;
use App\Models\PettyCashAdvance;
use App\Models\PurchaseReturnVoucher;
use App\Models\PurchaseVoucher;
use App\Models\ReceiptVoucher;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingPostingService
{
    public function createJournalVoucher(array $data): JournalVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = (int) ($data['business_id'] ?? AppController::businessId());
            $entries = $data['entries'] ?? [];
            $requestedStatus = $data['status'] ?? 'draft';
            $this->validatePeriod($businessId, $data['voucher_date']);
            $this->validateBalancedVoucher($entries);

            $voucher = JournalVoucher::query()->create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'] ?? null,
                'voucher_number' => $data['voucher_number'] ?? $this->nextNumber($businessId, $data['voucher_type'] ?? 'journal'),
                'voucher_type' => $data['voucher_type'] ?? 'journal',
                'voucher_date' => $data['voucher_date'],
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'narration' => $data['narration'] ?? null,
                'total_debit' => collect($entries)->sum(fn ($entry) => (float) ($entry['debit_amount'] ?? 0)),
                'total_credit' => collect($entries)->sum(fn ($entry) => (float) ($entry['credit_amount'] ?? 0)),
                'status' => in_array($requestedStatus, ['posted', 'approved'], true) ? 'draft' : $requestedStatus,
                'is_system_generated' => (bool) ($data['is_system_generated'] ?? false),
                'created_by' => Auth::id(),
            ]);

            foreach ($entries as $entry) {
                $this->entry($voucher, $entry);
            }

            if (in_array($requestedStatus, ['posted', 'approved'], true)) {
                $this->postJournalVoucher($voucher);
            }

            return $voucher->fresh(['entries.account']);
        });
    }

    public function postJournalVoucher(JournalVoucher $voucher): JournalVoucher
    {
        return DB::transaction(function () use ($voucher) {
            $this->assertBusiness($voucher->business_id);
            if (!in_array($voucher->status, ['draft', 'posted'], true)) {
                throw ValidationException::withMessages(['status' => 'Only draft or posted vouchers can be approved.']);
            }
            $this->validateBalancedVoucher($voucher->entries->toArray());

            if ($voucher->status === 'draft') {
                foreach ($voucher->entries as $entry) {
                    $this->applyBalance($entry->account_id, (float) $entry->debit_amount, (float) $entry->credit_amount);
                }
            }

            $voucher->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);

            return $voucher->fresh(['entries.account']);
        });
    }

    public function addDebitEntry(array &$entries, int $accountId, float $amount, array $extra = []): void
    {
        if ($amount > 0) $entries[] = array_merge($extra, ['account_id' => $accountId, 'debit_amount' => round($amount, 2), 'credit_amount' => 0]);
    }

    public function addCreditEntry(array &$entries, int $accountId, float $amount, array $extra = []): void
    {
        if ($amount > 0) $entries[] = array_merge($extra, ['account_id' => $accountId, 'debit_amount' => 0, 'credit_amount' => round($amount, 2)]);
    }

    public function validateBalancedVoucher(array $entries): void
    {
        $debit = $credit = 0.0;
        foreach ($entries as $index => $entry) {
            $d = (float) ($entry['debit_amount'] ?? 0);
            $c = (float) ($entry['credit_amount'] ?? 0);
            if (($d > 0 && $c > 0) || ($d <= 0 && $c <= 0)) {
                throw ValidationException::withMessages(["entries.$index" => 'Enter either debit or credit amount.']);
            }
            $debit += $d; $credit += $c;
        }
        if (round($debit, 2) !== round($credit, 2)) {
            throw ValidationException::withMessages(['entries' => 'Total debit must equal total credit.']);
        }
    }

    public function reverseJournalVoucher(JournalVoucher $voucher, ?string $remarks = null): JournalVoucher
    {
        return DB::transaction(function () use ($voucher, $remarks) {
            $this->assertBusiness($voucher->business_id);
            if (!in_array($voucher->status, ['approved', 'posted'], true) || $voucher->reversed_by_id) {
                throw ValidationException::withMessages(['status' => 'Voucher cannot be reversed.']);
            }
            $entries = [];
            foreach ($voucher->entries as $entry) {
                $entries[] = [
                    'account_id' => $entry->account_id,
                    'customer_id' => $entry->customer_id,
                    'supplier_id' => $entry->supplier_id,
                    'employee_id' => $entry->employee_id ?? null,
                    'payroll_run_id' => $entry->payroll_run_id ?? null,
                    'employee_payroll_id' => $entry->employee_payroll_id ?? null,
                    'debit_amount' => (float) $entry->credit_amount,
                    'credit_amount' => (float) $entry->debit_amount,
                    'narration' => $remarks ?: 'Reversal of ' . $voucher->voucher_number,
                ];
            }
            $reversal = $this->createJournalVoucher([
                'business_id' => $voucher->business_id,
                'branch_id' => $voucher->branch_id,
                'voucher_type' => 'reversal',
                'voucher_date' => now()->toDateString(),
                'reference_type' => JournalVoucher::class,
                'reference_id' => $voucher->id,
                'reference_number' => $voucher->voucher_number,
                'narration' => $remarks ?: 'Voucher reversal',
                'status' => 'approved',
                'is_system_generated' => true,
                'entries' => $entries,
            ]);
            $voucher->update(['status' => 'reversed', 'reversed_by_id' => $reversal->id]);

            return $reversal;
        });
    }

    public function postSalesVoucher(SalesVoucher $sale): ?JournalVoucher
    {
        return DB::transaction(function () use ($sale) {
            if ($this->alreadyPosted(SalesVoucher::class, $sale->id)) return null;
            $s = $this->settings($sale->business_id);
            $entries = [];
            $this->addDebitEntry($entries, $sale->sale_type === 'cash' ? $s->cash_account_id : $s->accounts_receivable_id, (float) $sale->grand_total, ['customer_id' => $sale->customer_id]);
            $this->addCreditEntry($entries, $s->sales_account_id, (float) $sale->taxable_amount);
            $this->addCreditEntry($entries, $s->output_cgst_account_id, (float) $sale->cgst_amount);
            $this->addCreditEntry($entries, $s->output_sgst_account_id, (float) $sale->sgst_amount);
            $this->addCreditEntry($entries, $s->output_igst_account_id, (float) $sale->igst_amount);
            $this->addCreditEntry($entries, $s->output_cess_account_id, (float) $sale->cess_amount);
            $this->addCreditEntry($entries, $s->shipping_income_account_id, (float) $sale->shipping_amount);
            $this->round($entries, $s->round_off_account_id, (float) $sale->round_off);
            return $this->sourceVoucher('sales', $sale, $sale->invoice_date, $sale->invoice_number, $entries);
        });
    }

    public function postPurchaseVoucher(PurchaseVoucher $purchase): ?JournalVoucher
    {
        return DB::transaction(function () use ($purchase) {
            if ($this->alreadyPosted(PurchaseVoucher::class, $purchase->id)) return null;
            $s = $this->settings($purchase->business_id);
            $entries = [];
            $this->addDebitEntry($entries, $s->purchase_account_id, (float) $purchase->taxable_amount);
            $this->addDebitEntry($entries, $s->input_cgst_account_id, (float) $purchase->cgst_amount);
            $this->addDebitEntry($entries, $s->input_sgst_account_id, (float) $purchase->sgst_amount);
            $this->addDebitEntry($entries, $s->input_igst_account_id, (float) $purchase->igst_amount);
            $this->addDebitEntry($entries, $s->input_cess_account_id, (float) $purchase->cess_amount);
            $this->round($entries, $s->round_off_account_id, (float) $purchase->round_off);
            $this->addCreditEntry($entries, $purchase->purchase_type === 'cash' ? $s->cash_account_id : $s->accounts_payable_id, (float) $purchase->grand_total, ['supplier_id' => $purchase->supplier_id]);
            return $this->sourceVoucher('purchase', $purchase, $purchase->purchase_date, $purchase->voucher_number, $entries);
        });
    }

    public function postSalesReturn(SalesReturnVoucher $return): ?JournalVoucher
    {
        return DB::transaction(function () use ($return) {
            if ($this->alreadyPosted(SalesReturnVoucher::class, $return->id)) return null;
            $s = $this->settings($return->business_id);
            $entries = [];
            $this->addDebitEntry($entries, $s->sales_return_account_id, (float) $return->taxable_amount);
            $this->addDebitEntry($entries, $s->output_cgst_account_id, (float) $return->cgst_amount);
            $this->addDebitEntry($entries, $s->output_sgst_account_id, (float) $return->sgst_amount);
            $this->addDebitEntry($entries, $s->output_igst_account_id, (float) $return->igst_amount);
            $this->addDebitEntry($entries, $s->output_cess_account_id, (float) $return->cess_amount);
            $this->addCreditEntry($entries, $return->refund_amount > 0 ? $s->cash_account_id : $s->accounts_receivable_id, (float) $return->grand_total, ['customer_id' => $return->customer_id]);
            return $this->sourceVoucher('sales_return', $return, $return->return_date, $return->credit_note_number, $entries);
        });
    }

    public function postPurchaseReturn(PurchaseReturnVoucher $return): ?JournalVoucher
    {
        return DB::transaction(function () use ($return) {
            if ($this->alreadyPosted(PurchaseReturnVoucher::class, $return->id)) return null;
            $s = $this->settings($return->business_id);
            $entries = [];
            $this->addDebitEntry($entries, $s->accounts_payable_id, (float) $return->grand_total, ['supplier_id' => $return->supplier_id]);
            $this->addCreditEntry($entries, $s->purchase_return_account_id, (float) $return->taxable_amount);
            $this->addCreditEntry($entries, $s->input_cgst_account_id, (float) $return->cgst_amount);
            $this->addCreditEntry($entries, $s->input_sgst_account_id, (float) $return->sgst_amount);
            $this->addCreditEntry($entries, $s->input_igst_account_id, (float) $return->igst_amount);
            $this->addCreditEntry($entries, $s->input_cess_account_id, (float) $return->cess_amount);
            return $this->sourceVoucher('purchase_return', $return, $return->return_date, $return->voucher_number, $entries);
        });
    }

    public function postCustomerReceipt(ReceiptVoucher $receipt, array $allocations = []): JournalVoucher
    {
        return DB::transaction(function () use ($receipt, $allocations) {
            $s = $this->settings($receipt->business_id);
            $entries = [];
            $this->addDebitEntry($entries, $receipt->cash_bank_account_id, (float) $receipt->amount);
            $this->addDebitEntry($entries, $s->discount_allowed_account_id, (float) $receipt->discount_allowed);
            $this->addCreditEntry($entries, $s->accounts_receivable_id, (float) $receipt->amount + (float) $receipt->discount_allowed + (float) $receipt->write_off_amount, ['customer_id' => $receipt->customer_id]);
            $journal = $this->sourceVoucher('receipt', $receipt, $receipt->receipt_date, $receipt->voucher_number, $entries);
            $receipt->update(['status' => 'approved', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->allocations($journal, $allocations, 'customer_receipt', $receipt->receipt_date);
            return $journal;
        });
    }

    public function postSupplierPayment(PaymentVoucher $payment, array $allocations = []): JournalVoucher
    {
        return DB::transaction(function () use ($payment, $allocations) {
            $s = $this->settings($payment->business_id);
            $entries = [];
            $this->addDebitEntry($entries, $s->accounts_payable_id, (float) $payment->amount + (float) $payment->discount_received + (float) $payment->write_off_amount, ['supplier_id' => $payment->supplier_id]);
            $this->addCreditEntry($entries, $payment->cash_bank_account_id, (float) $payment->amount);
            $this->addCreditEntry($entries, $s->discount_received_account_id, (float) $payment->discount_received);
            $journal = $this->sourceVoucher('payment', $payment, $payment->payment_date, $payment->voucher_number, $entries);
            $payment->update(['status' => 'approved', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->allocations($journal, $allocations, 'supplier_payment', $payment->payment_date);
            return $journal;
        });
    }

    public function postContraVoucher(array $data): JournalVoucher
    {
        $from = Account::query()->where('business_id', AppController::businessId())->whereIn('account_type', ['cash', 'bank'])->findOrFail($data['from_account_id']);
        $to = Account::query()->where('business_id', AppController::businessId())->whereIn('account_type', ['cash', 'bank'])->findOrFail($data['to_account_id']);
        if ($from->id === $to->id) throw ValidationException::withMessages(['to_account_id' => 'From and To accounts cannot be same.']);
        $entries = [];
        $this->addDebitEntry($entries, $to->id, (float) $data['amount']);
        $this->addCreditEntry($entries, $from->id, (float) $data['amount']);
        return $this->createJournalVoucher(['voucher_type' => 'contra', 'voucher_date' => $data['voucher_date'], 'reference_number' => $data['reference_number'] ?? null, 'narration' => $data['remarks'] ?? null, 'status' => 'approved', 'entries' => $entries]);
    }

    public function postExpenseVoucher(ExpenseVoucher $expense): ?JournalVoucher
    {
        return DB::transaction(function () use ($expense) {
            if ($this->alreadyPosted(ExpenseVoucher::class, $expense->id)) return null;
            $s = $this->settings($expense->business_id);
            $entries = [];
            foreach ($expense->items as $item) {
                $this->addDebitEntry($entries, $item->expense_account_id, (float) $item->taxable_amount + max(0, (float) $item->line_total - (float) $item->taxable_amount - (float) $item->cgst_amount - (float) $item->sgst_amount - (float) $item->igst_amount - (float) $item->cess_amount), ['supplier_id' => $expense->supplier_id]);
            }
            $this->addDebitEntry($entries, $s->input_cgst_account_id, (float) $expense->cgst_amount);
            $this->addDebitEntry($entries, $s->input_sgst_account_id, (float) $expense->sgst_amount);
            $this->addDebitEntry($entries, $s->input_igst_account_id, (float) $expense->igst_amount);
            $this->addDebitEntry($entries, $s->input_cess_account_id, (float) $expense->cess_amount);
            $this->addCreditEntry($entries, $expense->paid_from_account_id ?: $s->accounts_payable_id, (float) $expense->net_paid_amount, ['supplier_id' => $expense->supplier_id]);
            $payableAccount = $expense->payment_mode === 'employee_reimbursement' ? ($s->employee_reimbursement_payable_account_id ?: $s->accounts_payable_id) : $s->accounts_payable_id;
            $this->addCreditEntry($entries, $payableAccount, round((float) $expense->total_amount - (float) $expense->net_paid_amount - (float) $expense->tds_amount, 2), ['supplier_id' => $expense->supplier_id]);
            $this->addCreditEntry($entries, $s->tds_payable_account_id ?: $s->accounts_payable_id, (float) $expense->tds_amount, ['supplier_id' => $expense->supplier_id, 'narration' => 'TDS payable']);
            return $this->sourceVoucher('expense', $expense, $expense->expense_date, $expense->voucher_number, $entries);
        });
    }

    public function postOtherIncomeVoucher(OtherIncomeVoucher $income): ?JournalVoucher
    {
        return DB::transaction(function () use ($income) {
            if ($this->alreadyPosted(OtherIncomeVoucher::class, $income->id)) return null;
            $s = $this->settings($income->business_id);
            $entries = [];
            $this->addDebitEntry($entries, $income->received_into_account_id, (float) $income->total_amount, ['customer_id' => $income->customer_id]);
            $this->addCreditEntry($entries, $income->income_account_id, (float) $income->taxable_amount + (float) $income->non_taxable_amount);
            $this->addCreditEntry($entries, $s->output_cgst_account_id, (float) $income->cgst_amount);
            $this->addCreditEntry($entries, $s->output_sgst_account_id, (float) $income->sgst_amount);
            $this->addCreditEntry($entries, $s->output_igst_account_id, (float) $income->igst_amount);
            $this->addCreditEntry($entries, $s->output_cess_account_id, (float) $income->cess_amount);
            return $this->sourceVoucher('other_income', $income, $income->income_date, $income->voucher_number, $entries);
        });
    }

    public function postPettyCashAdvance(PettyCashAdvance $advance): ?JournalVoucher
    {
        return DB::transaction(function () use ($advance) {
            if ($this->alreadyPosted(PettyCashAdvance::class, $advance->id)) return null;
            $s = $this->settings($advance->business_id);
            $entries = [];
            $this->addDebitEntry($entries, $s->petty_cash_advance_account_id ?: $s->supplier_advance_account_id, (float) $advance->amount, ['narration' => 'Employee petty cash advance']);
            $this->addCreditEntry($entries, $advance->cash_account_id, (float) $advance->amount);
            return $this->sourceVoucher('petty_cash', $advance, $advance->advance_date, $advance->voucher_number, $entries);
        });
    }

    public function getAccountBalance(int $accountId): float
    {
        $account = Account::query()->where('business_id', AppController::businessId())->findOrFail($accountId);
        return (float) $account->current_balance;
    }

    public function getCustomerOutstanding(?int $customerId = null)
    {
        return $this->partyOutstanding('customer', $customerId);
    }

    public function getSupplierOutstanding(?int $supplierId = null)
    {
        return $this->partyOutstanding('supplier', $supplierId);
    }

    private function sourceVoucher(string $type, $source, $date, string $number, array $entries): JournalVoucher
    {
        return $this->createJournalVoucher([
            'business_id' => $source->business_id, 'branch_id' => $source->branch_id ?? null, 'voucher_type' => $type,
            'voucher_date' => optional($date)->format('Y-m-d') ?: $date, 'reference_type' => get_class($source), 'reference_id' => $source->id,
            'reference_number' => $number, 'narration' => ucfirst(str_replace('_', ' ', $type)) . ' posting', 'status' => 'approved',
            'is_system_generated' => true, 'entries' => $entries,
        ]);
    }

    private function entry(JournalVoucher $voucher, array $entry)
    {
        $account = Account::query()->where('business_id', $voucher->business_id)->findOrFail($entry['account_id']);
        return $voucher->entries()->create([
            'business_id' => $voucher->business_id, 'branch_id' => $voucher->branch_id, 'account_id' => $account->id,
            'customer_id' => $entry['customer_id'] ?? null, 'supplier_id' => $entry['supplier_id'] ?? null, 'fixed_asset_id' => $entry['fixed_asset_id'] ?? null,
            'employee_id' => $entry['employee_id'] ?? null, 'payroll_run_id' => $entry['payroll_run_id'] ?? null, 'employee_payroll_id' => $entry['employee_payroll_id'] ?? null,
            'debit_amount' => $entry['debit_amount'] ?? 0, 'credit_amount' => $entry['credit_amount'] ?? 0,
            'due_date' => $entry['due_date'] ?? null, 'reference_type' => $voucher->reference_type, 'reference_id' => $voucher->reference_id,
            'narration' => $entry['narration'] ?? $voucher->narration,
        ]);
    }

    private function applyBalance(int $accountId, float $debit, float $credit): void
    {
        $account = Account::query()->lockForUpdate()->findOrFail($accountId);
        $nature = optional($account->group)->nature ?: (in_array($account->account_type, ['asset','expense','cash','bank','customer','inventory'], true) ? 'debit' : 'credit');
        $change = $nature === 'debit' ? $debit - $credit : $credit - $debit;
        $account->update(['current_balance' => round((float) $account->current_balance + $change, 2)]);
    }

    private function settings(int $businessId): BusinessAccountSetting
    {
        $settings = BusinessAccountSetting::query()->where('business_id', $businessId)->first();
        if (!$settings) throw ValidationException::withMessages(['account_settings' => 'Business default accounts are not configured.']);
        return $settings;
    }

    private function alreadyPosted(string $type, int $id): bool
    {
        return JournalVoucher::query()->where('business_id', AppController::businessId())->where('reference_type', $type)->where('reference_id', $id)->whereIn('status', ['posted', 'approved'])->exists();
    }

    private function nextNumber(int $businessId, string $type): string
    {
        $prefix = strtoupper(substr($type, 0, 3)) . '-' . date('Y') . '-';
        $last = JournalVoucher::query()->where('business_id', $businessId)->where('voucher_number', 'like', $prefix . '%')->lockForUpdate()->orderByDesc('id')->value('voucher_number');
        return $prefix . str_pad((string) ($last ? ((int) substr($last, strlen($prefix)) + 1) : 1), 5, '0', STR_PAD_LEFT);
    }

    private function validatePeriod(int $businessId, string $date): void
    {
        $locked = DB::table('accounting_periods')->where('business_id', $businessId)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->where('status', 'locked')->exists();
        if ($locked) throw ValidationException::withMessages(['voucher_date' => 'Accounting period is locked.']);
    }

    private function allocations(JournalVoucher $journal, array $allocations, string $type, string $date): void
    {
        $entry = $journal->entries()->first();
        foreach ($allocations as $allocation) {
            LedgerAllocation::query()->create([
                'business_id' => $journal->business_id, 'journal_entry_id' => $entry->id, 'allocation_type' => $type,
                'reference_type' => $allocation['reference_type'], 'reference_id' => $allocation['reference_id'],
                'original_amount' => $allocation['original_amount'] ?? $allocation['allocated_amount'],
                'allocated_amount' => $allocation['allocated_amount'], 'discount_amount' => $allocation['discount_amount'] ?? 0,
                'write_off_amount' => $allocation['write_off_amount'] ?? 0, 'allocation_date' => $date, 'created_by' => Auth::id(),
            ]);
        }
    }

    private function round(array &$entries, ?int $accountId, float $amount): void
    {
        if (!$accountId || $amount == 0) return;
        $amount > 0 ? $this->addCreditEntry($entries, $accountId, abs($amount)) : $this->addDebitEntry($entries, $accountId, abs($amount));
    }

    private function partyOutstanding(string $party, ?int $id)
    {
        $businessId = AppController::businessId();
        $column = $party . '_id';
        return DB::table('journal_entries')->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_entries.journal_voucher_id')
            ->where('journal_entries.business_id', $businessId)->whereNotNull("journal_entries.$column")->when($id, fn ($q) => $q->where("journal_entries.$column", $id))
            ->whereIn('journal_vouchers.status', ['posted', 'approved'])
            ->selectRaw("journal_entries.$column as party_id, SUM(debit_amount) as debit, SUM(credit_amount) as credit")
            ->groupBy("journal_entries.$column")->get();
    }

    private function assertBusiness(int $businessId): void
    {
        abort_unless($businessId === AppController::businessId(), 404);
    }
}
