<?php
use Illuminate\Support\Facades\DB;
use App\Models\PurchaseVoucher;
DB::beginTransaction();
try {
    $p = PurchaseVoucher::where('voucher_number', 'PUR-2026-00002')->firstOrFail();
    session(['business_id' => $p->business_id]);
    $check = function ($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; };
    $check((float)$p->balance_amount === 450.0, 'remaining outstanding 450');
    $service = app(App\Services\AccountingService::class);
    $account = App\Models\Account::where('business_id', $p->business_id)->whereIn('account_type', ['cash', 'bank'])->where('status', 'active')->firstOrFail();
    $data = ['supplier_id'=>$p->supplier_id,'payment_date'=>now()->toDateString(),'payment_mode'=>'cash','cash_bank_account_id'=>$account->id,'amount'=>450,'status'=>'approved','discount_received'=>0,'write_off_amount'=>0,'allocations'=>[['reference_type'=>PurchaseVoucher::class,'reference_id'=>$p->id,'allocated_amount'=>450]]];
    $payment = $service->createPayment($data);
    $check((int)$payment->branch_id === (int)$p->branch_id, 'payment derives purchase branch');
    $check((int)$payment->journal->branch_id === (int)$p->branch_id, 'journal header retains branch');
    $check($payment->journal->entries()->where('branch_id', $p->branch_id)->count() === $payment->journal->entries()->count(), 'all journal lines retain branch');
    $check((float)$payment->journal->entries()->where('account_id',$account->id)->sum('credit_amount') === 450.0, 'cash/bank outflow 450');
    $p->refresh();
    $check((float)$p->balance_amount === 0.0, 'purchase settled');
    $check((float)$service->ledger(['supplier_id'=>$p->supplier_id])->last()->running_balance === 0.0, 'supplier balance zero');
    $check((float)$service->supplierOutstanding($p->supplier_id)->firstWhere('purchase_id',$p->id)['outstanding_amount'] === 0.0, 'bill no longer has positive outstanding');
} finally { DB::rollBack(); echo "ROLLED BACK: no payment retained.\n"; }
