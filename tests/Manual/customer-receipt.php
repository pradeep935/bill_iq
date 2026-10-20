<?php
use Illuminate\Support\Facades\DB;
use App\Models\SalesVoucher;
DB::beginTransaction();
try {
 $i=SalesVoucher::where('invoice_number','INV-2026-00025')->firstOrFail(); session(['business_id'=>$i->business_id]);
 $s=app(App\Services\AccountingService::class);
 $check=function($ok,$m){if(!$ok)throw new RuntimeException($m);echo "PASS: $m\n";};
 $check((float)$i->grand_total===360.0 && (float)$i->paid_amount===0.0,'invoice 360 unpaid');
 $a=App\Models\Account::where('business_id',$i->business_id)->where('account_type','cash')->firstOrFail();
 $data=['customer_id'=>$i->customer_id,'receipt_date'=>now()->toDateString(),'receipt_mode'=>'cash','cash_bank_account_id'=>$a->id,'amount'=>200,'status'=>'approved','discount_allowed'=>0,'write_off_amount'=>0,'allocations'=>[['reference_type'=>SalesVoucher::class,'reference_id'=>$i->id,'allocated_amount'=>200]]];
 $reject=function($d,$m)use($s){try{$s->createReceipt($d);throw new RuntimeException('Accepted '.$m);}catch(Illuminate\Validation\ValidationException $e){echo "PASS: rejected $m\n";}};
 $b=App\Models\Branch::create(['business_id'=>$i->business_id,'name'=>'Rollback receipt branch','code'=>'RCPT-TEST','status'=>'active']);
 $other=$i->replicate();$other->invoice_number='RCPT-ROLLBACK-TEST';$other->voucher_number='RCPT-ROLLBACK-TEST';$other->public_token=null;$other->branch_id=$b->id;$other->save();
 $bad=$data;$bad['amount']=201;$bad['allocations'][]=['reference_type'=>SalesVoucher::class,'reference_id'=>$other->id,'allocated_amount'=>1];$reject($bad,'mixed branches');
 $bad=$data;$bad['branch_id']=$b->id;$reject($bad,'branch mismatch');
 $bad=$data;$bad['customer_id']=999999;$reject($bad,'wrong customer');
 $bad=$data;$bad['amount']=400;$bad['allocations'][0]['allocated_amount']=400;$reject($bad,'over allocation');
 $cashBefore=(float)($s->cashBankBook(['account_id'=>$a->id])->last()->running_balance??0);
 foreach([200,160] as $amount){
  $data['amount']=$amount;$data['allocations'][0]['allocated_amount']=$amount;$r=$s->createReceipt($data);$j=$r->journal;$i->refresh();
  $check((int)$r->branch_id===(int)$i->branch_id && (int)$j->branch_id===(int)$i->branch_id && $j->entries()->where('branch_id',$i->branch_id)->count()===$j->entries()->count(),'receipt/header/lines branch');
  $check((float)$j->entries()->where('account_id',$a->id)->sum('debit_amount')===(float)$amount,'cash debit '.$amount);
  $line=$j->entries()->where('customer_id',$i->customer_id)->firstOrFail();$check((float)$line->credit_amount===(float)$amount,'receivable credit '.$amount);
  $check(DB::table('ledger_allocations')->where('journal_entry_id',$line->id)->where('reference_type',SalesVoucher::class)->where('reference_id',$i->id)->exists(),'allocation customer line and source link');
  if($amount===200){
   $check((float)$i->paid_amount===200.0 && (float)$i->balance_amount===160.0 && $i->payment_status==='partial','paid 200 balance 160 partial');
   $check((float)$s->customerOutstanding($i->customer_id)->firstWhere('invoice_id',$i->id)['outstanding_amount']===160.0,'Customer Outstanding 160');
   $check((float)$s->ledger(['customer_id'=>$i->customer_id])->last()->running_balance===160.0,'customer running balance 160');
   $check((float)$s->cashBankBook(['account_id'=>$a->id])->last()->running_balance===$cashBefore+200,'cash book increased 200');
  }
 }
 $check((float)$i->paid_amount===360.0 && (float)$i->balance_amount===0.0 && $i->payment_status==='paid','fully paid 360');
 $check(!$s->customerOutstanding($i->customer_id)->filter(fn($r)=>$r['outstanding_amount']>0)->contains('invoice_id',$i->id),'closed invoice excluded');
 $l=$s->ledger(['customer_id'=>$i->customer_id]);$check((float)$l->last()->running_balance===0.0 && $l->where('voucher_type','receipt')->count()>=2 && $l->where('reference_number',$i->invoice_number)->count()>=1,'history preserved and balance zero');
}finally{DB::rollBack();echo "ROLLED BACK: no test writes retained.\n";}
