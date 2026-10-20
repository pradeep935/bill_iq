<?php
use Illuminate\Support\Facades\DB;
DB::beginTransaction();
try {
 session(['business_id'=>1]);
 $service=app(App\Services\AccountingService::class);
 $snapshot=fn()=>[$service->customerOutstanding(3)->toArray(),$service->ledger(['customer_id'=>3])->toArray(),App\Models\JournalVoucher::count()];
 $before=json_encode($snapshot());
 foreach(['REV-2026-00001','REV-2026-00002','REC-2026-00002'] as $number){
  $j=App\Models\JournalVoucher::where('business_id',1)->where('voucher_number',$number)->firstOrFail();
  try{$service->reverseJournal($j->id,'Eligibility regression');throw new RuntimeException('Accepted '.$number);}
  catch(Illuminate\Validation\ValidationException $e){echo "PASS: rejected $number\n";}
 }
 if(json_encode($snapshot())!==$before)throw new RuntimeException('Ledger/outstanding/journals changed');
 echo "PASS: ledger, outstanding and journal count unchanged\n";
}finally{DB::rollBack();}
