<?php
// Run via artisan tinker: require this file. All test writes are rolled back.
use Illuminate\Support\Facades\DB;
use App\Models\PurchaseVoucher;
use App\Services\AccountingService;

DB::beginTransaction();
try {
    $purchase = PurchaseVoucher::where('voucher_number', 'PUR-2026-00002')->firstOrFail();
    session(['business_id' => $purchase->business_id]);
    $service = app(AccountingService::class);
    $account = App\Models\Account::where('business_id', $purchase->business_id)->whereIn('account_type', ['cash', 'bank'])->where('status', 'active')->firstOrFail();
    $check = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; };
    $pay = function ($amount, $allocation = null) use ($service, $purchase, $account) {
        return $service->createPayment([
            'supplier_id' => $purchase->supplier_id, 'payment_date' => now()->toDateString(),
            'payment_mode' => 'cash', 'cash_bank_account_id' => $account->id,
            'amount' => $amount, 'discount_received' => 0, 'write_off_amount' => 0, 'status' => 'approved',
            'allocations' => [['reference_type' => PurchaseVoucher::class, 'reference_id' => $purchase->id, 'allocated_amount' => $allocation ?? $amount]],
        ]);
    };
    $check((float)$purchase->balance_amount === 1050.0, 'starting outstanding is 1050');
    $first = $pay(600);
    $purchase->refresh();
    $check((float)$purchase->paid_amount === 600.0 && (float)$purchase->balance_amount === 450.0, 'partial payment leaves 450');
    $check((float)$service->supplierOutstanding($purchase->supplier_id)->firstWhere('purchase_id', $purchase->id)['outstanding_amount'] === 450.0, 'outstanding report reflects 450');
    $check((float)$first->journal->entries()->where('supplier_id', $purchase->supplier_id)->sum('debit_amount') === 600.0, 'supplier debit is 600');
    $check((float)$first->journal->entries()->where('account_id', $account->id)->sum('credit_amount') === 600.0, 'cash/bank outflow is 600');
    try { $pay(500); throw new RuntimeException('Over-allocation accepted'); }
    catch (Illuminate\Validation\ValidationException $e) { echo 'PASS: over-allocation rejected: '.$e->getMessage()."\n"; }
    try { $pay(450, 400); throw new RuntimeException('Unallocated payment accepted'); }
    catch (Illuminate\Validation\ValidationException $e) { echo "PASS: unallocated payment rejected\n"; }
    $pay(450);
    $purchase->refresh();
    $check((float)$purchase->paid_amount === 1050.0 && (float)$purchase->balance_amount === 0.0, 'second payment settles purchase');
    $check((float)$service->ledger(['supplier_id' => $purchase->supplier_id])->last()->running_balance === 0.0, 'supplier running balance is zero');
} finally {
    DB::rollBack();
    echo "ROLLED BACK: purchase and accounting test writes were not retained.\n";
}
