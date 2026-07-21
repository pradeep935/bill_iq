<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptVoucher extends Model
{
    protected $table = 'receipt_vouchers';
    protected $guarded = [];

    protected $casts = [
        'receipt_date' => 'date',
        'instrument_date' => 'date',
        'amount' => 'decimal:2',
        'discount_allowed' => 'decimal:2',
        'write_off_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id');
    }
}
