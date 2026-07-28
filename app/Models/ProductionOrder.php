<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionOrder extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'planned_quantity' => 'decimal:3',
        'produced_quantity' => 'decimal:3',
        'rejected_quantity' => 'decimal:3',
        'additional_cost' => 'decimal:2',
        'production_cost' => 'decimal:2',
        'cost_per_unit' => 'decimal:2',
        'start_date' => 'date',
        'expected_completion_date' => 'date',
        'actual_completion_date' => 'datetime',
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function bom() { return $this->belongsTo(Bom::class); }
    public function finishedProduct() { return $this->belongsTo(Product::class, 'finished_product_id'); }
    public function finishedVariant() { return $this->belongsTo(ProductVariantItem::class, 'finished_product_variant_id'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function sourceWarehouse() { return $this->belongsTo(Warehouse::class, 'source_warehouse_id'); }
    public function finishedWarehouse() { return $this->belongsTo(Warehouse::class, 'finished_goods_warehouse_id'); }
    public function items() { return $this->hasMany(ProductionOrderItem::class); }
    public function wastages() { return $this->hasMany(ProductionWastage::class); }
}
