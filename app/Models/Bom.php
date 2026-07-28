<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bom extends Model
{
    use SoftDeletes;

    protected $table = 'boms';
    protected $guarded = [];

    protected $casts = [
        'output_quantity' => 'decimal:3',
        'wastage_percentage' => 'decimal:3',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'approved_at' => 'datetime',
    ];

    public function finishedProduct() { return $this->belongsTo(Product::class, 'finished_product_id'); }
    public function finishedVariant() { return $this->belongsTo(ProductVariantItem::class, 'finished_product_variant_id'); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function items() { return $this->hasMany(BomItem::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
