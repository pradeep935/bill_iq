<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentVoucher extends Model
{
    protected $table = 'stock_adjustment_vouchers';
    protected $guarded = [];

    protected $casts = [
        'adjustment_date' => 'date',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_quantity_in' => 'decimal:3',
        'total_quantity_out' => 'decimal:3',
        'total_value_in' => 'decimal:2',
        'total_value_out' => 'decimal:2',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function reason() { return $this->belongsTo(StockAdjustmentReason::class, 'adjustment_reason_id'); }
    public function journal() { return $this->belongsTo(JournalVoucher::class, 'journal_voucher_id'); }
    public function items() { return $this->hasMany(StockAdjustmentItem::class); }
}
