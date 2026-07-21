<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    protected $table = 'bank_statement_lines';
    protected $guarded = [];

    protected $casts = [
        'transaction_date' => 'date',
        'value_date' => 'date',
        'matched_at' => 'datetime',
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
    ];

    public function import() { return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id'); }
    public function bankAccount() { return $this->belongsTo(Account::class, 'bank_account_id'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class, 'matched_journal_entry_id'); }
}
