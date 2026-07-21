<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseItem extends Model
{
    protected $table = 'expense_items';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'cess_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function voucher() { return $this->belongsTo(ExpenseVoucher::class, 'expense_voucher_id'); }
    public function category() { return $this->belongsTo(ExpenseCategory::class, 'expense_category_id'); }
    public function account() { return $this->belongsTo(Account::class, 'expense_account_id'); }
}
