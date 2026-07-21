<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtherIncomeVoucher extends Model
{
    protected $table = 'other_income_vouchers';
    protected $guarded = [];

    protected $casts = [
        'income_date' => 'date',
        'approved_at' => 'datetime',
        'taxable_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'cess_amount' => 'decimal:2',
        'non_taxable_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function category() { return $this->belongsTo(IncomeCategory::class, 'income_category_id'); }
    public function account() { return $this->belongsTo(Account::class, 'income_account_id'); }
    public function receivedIntoAccount() { return $this->belongsTo(Account::class, 'received_into_account_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function journal() { return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id'); }
}
