<?php
// Read-only regression check for the posted supplier cash payments.
$payments = App\Models\PaymentVoucher::whereIn('voucher_number', ['PAY-2026-00001', 'PAY-2026-00002'])->get();
if ($payments->count() !== 2) throw new RuntimeException('Expected two test payments.');
foreach ($payments as $payment) {
    session(['business_id' => $payment->business_id]);
    $rows = app(App\Services\AccountingService::class)->cashBankBook(['account_id' => $payment->cash_bank_account_id]);
    $row = $rows->first(fn ($row) => $row->reference_number === $payment->voucher_number && $row->voucher_type === 'payment');
    if (!$row || (float)$row->credit_amount !== (float)$payment->amount || (float)$row->debit_amount !== 0.0) throw new RuntimeException('Missing cash credit: '.$payment->voucher_number);
    echo 'PASS: '.$payment->voucher_number.' cash credit '.$row->credit_amount."\n";
}
