<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionWastage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function order() { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(ProductBatch::class); }
}
