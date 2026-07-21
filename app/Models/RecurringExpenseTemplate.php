<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringExpenseTemplate extends Model
{
    protected $table = 'recurring_expense_templates';
    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'next_run_date' => 'date',
        'end_date' => 'date',
        'amount' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'auto_create_draft' => 'boolean',
        'auto_post' => 'boolean',
        'approval_required' => 'boolean',
    ];

    public function category() { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
    public function account() { return $this->belongsTo(Account::class, 'expense_account_id'); }
    public function paidFromAccount() { return $this->belongsTo(Account::class, 'paid_from_account_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
}
