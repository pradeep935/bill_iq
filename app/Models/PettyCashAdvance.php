<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PettyCashAdvance extends Model
{
    protected $table = 'petty_cash_advances';
    protected $guarded = [];

    protected $casts = [
        'advance_date' => 'date',
        'amount' => 'decimal:2',
        'settled_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
    ];

    public function cashAccount() { return $this->belongsTo(Account::class, 'cash_account_id'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function journal() { return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id'); }
}
