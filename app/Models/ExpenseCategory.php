<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseCategory extends Model
{
    use SoftDeletes;

    protected $table = 'expense_categories';
    protected $guarded = [];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function vouchers()
    {
        return $this->hasMany(ExpenseVoucher::class);
    }
}
