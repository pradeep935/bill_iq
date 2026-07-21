<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomeCategory extends Model
{
    use SoftDeletes;

    protected $table = 'income_categories';
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
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function vouchers()
    {
        return $this->hasMany(OtherIncomeVoucher::class);
    }
}
