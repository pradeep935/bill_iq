<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';
    protected $guarded = [];

    protected $casts = [
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function voucher()
    {
        return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function bankReconciliationItems()
    {
        return $this->hasMany(BankReconciliationItem::class);
    }
}
