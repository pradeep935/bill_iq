<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentVoucher extends Model
{
    protected $table = 'payment_vouchers';
    protected $guarded = [];

    protected $casts = [
        'payment_date' => 'date',
        'instrument_date' => 'date',
        'amount' => 'decimal:2',
        'discount_received' => 'decimal:2',
        'write_off_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id');
    }
}
