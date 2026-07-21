<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialYearClosure extends Model
{
    protected $table = 'financial_year_closures';
    protected $guarded = [];

    protected $casts = [
        'closing_date' => 'date',
        'profit_loss_amount' => 'decimal:2',
        'checklist_json' => 'array',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function closingJournal() { return $this->belongsTo(JournalVoucher::class, 'closing_journal_voucher_id'); }
    public function retainedEarningsAccount() { return $this->belongsTo(Account::class, 'retained_earnings_account_id'); }
}
