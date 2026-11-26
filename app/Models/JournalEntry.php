<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';
    protected $guarded = [];

    protected $casts = [
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function voucher()
    {
        return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function bankReconciliationItems()
    {
        return $this->hasMany(BankReconciliationItem::class);
    }

    // What a customer or supplier owes is every line in their name except the cash and bank ones:
    // money paid or received at billing also carries the party, but it is not a due.
    public static function withoutMoneyLines($query, int $businessId)
    {
        return $query->whereNotIn('journal_entries.account_id', Account::query()->where('business_id', $businessId)->whereIn('account_type', ['cash', 'bank'])->select('id'));
    }
}
