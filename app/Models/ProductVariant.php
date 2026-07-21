<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $table = 'product_variants';

    protected $guarded = [];

    public function values()
    {
        return $this->hasMany(ProductVariantValue::class, 'variant_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
