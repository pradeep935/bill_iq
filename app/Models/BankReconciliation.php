<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    protected $table = 'bank_reconciliations';
    protected $guarded = [];

    protected $casts = [
        'statement_start_date' => 'date',
        'statement_end_date' => 'date',
        'statement_closing_balance' => 'decimal:2',
        'ledger_closing_balance' => 'decimal:2',
        'difference_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function bankAccount() { return $this->belongsTo(Account::class, 'bank_account_id'); }
    public function items() { return $this->hasMany(BankReconciliationItem::class); }
}
