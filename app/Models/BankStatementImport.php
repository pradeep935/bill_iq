<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementImport extends Model
{
    protected $table = 'bank_statement_imports';
    protected $guarded = [];

    protected $casts = [
        'statement_start_date' => 'date',
        'statement_end_date' => 'date',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
    ];

    public function bankAccount() { return $this->belongsTo(Account::class, 'bank_account_id'); }
    public function lines() { return $this->hasMany(BankStatementLine::class, 'bank_statement_import_id'); }
}
