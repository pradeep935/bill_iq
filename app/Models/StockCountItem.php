<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    protected $table = 'stock_count_items';
    protected $guarded = [];

    protected $casts = [
        'system_quantity' => 'decimal:3',
        'counted_quantity' => 'decimal:3',
        'variance_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'variance_value' => 'decimal:2',
        'counted_at' => 'datetime',
    ];

    public function session() { return $this->belongsTo(StockCountSession::class, 'stock_count_session_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(ProductBatch::class); }
}
