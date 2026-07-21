<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockAdjustmentReason extends Model
{
    use SoftDeletes;

    protected $table = 'stock_adjustment_reasons';
    protected $guarded = [];

    protected $casts = ['approval_required' => 'boolean', 'is_system' => 'boolean'];

    public function account() { return $this->belongsTo(Account::class, 'accounting_account_id'); }
    public function vouchers() { return $this->hasMany(StockAdjustmentVoucher::class, 'adjustment_reason_id'); }
}
