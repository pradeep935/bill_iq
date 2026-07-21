<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnRefund extends Model
{
    protected $table = 'sales_return_refunds';
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'refund_date' => 'date',
    ];

    public function voucher()
    {
        return $this->belongsTo(SalesReturnVoucher::class, 'sales_return_voucher_id');
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
