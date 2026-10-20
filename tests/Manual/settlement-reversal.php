<?php
use Illuminate\Support\Facades\DB;
use App\Models\JournalVoucher;
DB::beginTransaction();
try {
 session(['business_id'=>1]);
 $s=app(App\Services\AccountingService::class); $p=app(App\Services\AccountingPostingService::class);
 $check=function($ok,$m){if(!$ok)throw new RuntimeException($m);echo "PASS: $m\n";};
 $old=JournalVoucher::where('business_id',1)->where('voucher_number','REC-2026-00002')->firstOrFail();
 $cashBefore=(float)$s->cashBankBook(['account_id'=>1])->last()->running_balance;
 $p->reopenReversedSettlement($old);
 $invoice=App\Models\SalesVoucher::where('invoice_number','INV-2026-00025')->firstOrFail();
 $check((float)$invoice->paid_amount===200.0 && (float)$invoice->balance_amount===160.0 && $invoice->payment_status==='partial','historical reversal reopens invoice to 160');
 $check(App\Models\ReceiptVoucher::find($old->reference_id)->status==='reversed','receipt header reversed');
 $check((float)$s->ledger(['customer_id'=>$invoice->customer_id])->last()->running_balance===160.0,'customer ledger 160 including original and reversal');
 $check((float)$s->customerOutstanding($invoice->customer_id)->firstWhere('invoice_id',$invoice->id)['outstanding_amount']===160.0,'Customer Outstanding reopened');
 $p->reopenReversedSettlement($old); $invoice->refresh();
 $check((float)$invoice->balance_amount===160.0,'repair idempotent');
 $check((float)$s->cashBankBook(['account_id'=>1])->last()->running_balance===$cashBefore,'repair does not repost cash');
 foreach(['receipt','payment'] as $kind){
  $isReceipt=$kind==='receipt';
  $document=$isReceipt?$invoice:App\Models\PurchaseVoucher::where('voucher_number','PUR-2026-00002')->firstOrFail();
  $amount=$isReceipt?160:450;
  if(!$isReceipt){$existing=App\Models\PaymentVoucher::where('voucher_number','PAY-2026-00002')->firstOrFail();$p->reverseJournalVoucher($existing->journal,'Rollback payment reversal test');$document->refresh();$check((float)$document->balance_amount===450.0,'supplier reversal reopens 450');}
  $data=['cash_bank_account_id'=>1,'amount'=>$amount,'status'=>'approved','allocations'=>[['reference_type'=>get_class($document),'reference_id'=>$document->id,'allocated_amount'=>$amount]]];
  $data[$isReceipt?'customer_id':'supplier_id']=$document->{$isReceipt?'customer_id':'supplier_id'};
  $data[$kind.'_date']=now()->toDateString();$data[$kind.'_mode']='cash';
  $create=$isReceipt?'createReceipt':'createPayment';
  $settlement=$s->$create($data);$document->refresh();$check((float)$document->balance_amount===0.0,"$kind settles reopened balance");
  $before=(float)$s->cashBankBook(['account_id'=>1])->last()->running_balance;
  $rev=$p->reverseJournalVoucher($settlement->journal,'Rollback regression');$document->refresh();$settlement->refresh();
  $check($settlement->status==='reversed' && (int)$rev->reversal_of_id===(int)$settlement->journal_voucher_id,"$kind source and reversal linked");
  $check((float)$document->balance_amount===(float)$amount,"$kind reversal restores outstanding");
  $after=(float)$s->cashBankBook(['account_id'=>1])->last()->running_balance;
  $check($after===$before+($isReceipt?-$amount:$amount),"$kind cash reversal correct");
  $party=$isReceipt?'customer_id':'supplier_id';$balance=(float)$s->ledger([$party=>$document->$party])->last()->running_balance;
  $check($balance===($isReceipt?(float)$amount:-(float)$amount),"$kind party running balance correct");
  try{$p->reverseJournalVoucher($settlement->journal,'Duplicate');throw new RuntimeException('Double reversal accepted');}catch(Illuminate\Validation\ValidationException $e){echo "PASS: duplicate $kind reversal rejected\n";}
  $s->$create($data);$document->refresh();$check((float)$document->balance_amount===0.0,"$kind can settle again");
 }
}finally{DB::rollBack();echo "ROLLED BACK: regression data not retained.\n";}
