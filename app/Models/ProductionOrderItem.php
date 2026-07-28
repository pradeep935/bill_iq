<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'required_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'consumed_quantity' => 'decimal:3',
        'wastage_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function order() { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function rawMaterial() { return $this->belongsTo(Product::class, 'raw_material_product_id'); }
    public function rawVariant() { return $this->belongsTo(ProductVariantItem::class, 'raw_material_variant_id'); }
    public function batch() { return $this->belongsTo(ProductBatch::class); }
}
