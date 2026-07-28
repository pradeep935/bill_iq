<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BomItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_required' => 'decimal:3',
        'wastage_percentage' => 'decimal:3',
    ];

    public function bom() { return $this->belongsTo(Bom::class); }
    public function rawMaterial() { return $this->belongsTo(Product::class, 'raw_material_product_id'); }
    public function rawVariant() { return $this->belongsTo(ProductVariantItem::class, 'raw_material_variant_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
