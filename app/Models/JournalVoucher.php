<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalVoucher extends Model
{
    protected $table = 'journal_vouchers';
    protected $guarded = [];

    protected $casts = [
        'voucher_date' => 'date',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'is_system_generated' => 'boolean',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function entries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
