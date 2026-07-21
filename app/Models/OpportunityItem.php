<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpportunityItem extends Model
{
    protected $table = 'opportunity_items';
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:3', 'estimated_unit_price' => 'decimal:2', 'estimated_discount' => 'decimal:2', 'estimated_tax' => 'decimal:2', 'estimated_total' => 'decimal:2', 'probability_percent' => 'decimal:2'];
    public function opportunity() { return $this->belongsTo(Opportunity::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function variant() { return $this->belongsTo(ProductVariantItem::class, 'product_variant_id'); }
}
