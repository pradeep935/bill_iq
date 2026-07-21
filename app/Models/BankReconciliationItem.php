<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliationItem extends Model
{
    protected $table = 'bank_reconciliation_items';
    protected $guarded = [];

    protected $casts = [
        'matched_amount' => 'decimal:2',
    ];

    public function reconciliation() { return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id'); }
    public function statementLine() { return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class); }
}
