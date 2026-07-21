<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseVoucher extends Model
{
    protected $table = 'expense_vouchers';
    protected $guarded = [];

    protected $casts = [
        'expense_date' => 'date',
        'invoice_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'tds_applicable' => 'boolean',
        'taxable_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'cess_amount' => 'decimal:2',
        'non_taxable_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'tds_rate' => 'decimal:2',
        'tds_amount' => 'decimal:2',
        'net_paid_amount' => 'decimal:2',
    ];

    public function category() { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
    public function account() { return $this->belongsTo(Account::class, 'expense_account_id'); }
    public function paidFromAccount() { return $this->belongsTo(Account::class, 'paid_from_account_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function journal() { return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id'); }
    public function items() { return $this->hasMany(ExpenseItem::class); }
    public function attachments() { return $this->hasMany(ExpenseAttachment::class); }
}
