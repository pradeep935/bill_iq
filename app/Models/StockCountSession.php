<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountSession extends Model
{
    protected $table = 'stock_count_sessions';
    protected $guarded = [];

    protected $casts = [
        'count_date' => 'date',
        'freeze_stock' => 'boolean',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function items() { return $this->hasMany(StockCountItem::class); }
}
