<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $guarded = [];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'mrp' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'dealer_price' => 'decimal:2',
        'online_price' => 'decimal:2',
        'opening_stock' => 'decimal:3',
        'current_stock' => 'decimal:3',
        'minimum_stock' => 'decimal:3',
        'reorder_stock' => 'decimal:3',
        'maximum_stock' => 'decimal:3',
        'gst_rate' => 'decimal:2',
        'cess_rate' => 'decimal:2',
        'weight' => 'decimal:3',
        'length' => 'decimal:3',
        'width' => 'decimal:3',
        'height' => 'decimal:3',
        'tax_inclusive' => 'boolean',
        'expiry_required' => 'boolean',
        'batch_required' => 'boolean',
        'serial_required' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(ProductCategory::class, 'sub_category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function hsn()
    {
        return $this->belongsTo(HsnMaster::class, 'hsn_id');
    }

    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function variantItems()
    {
        return $this->hasMany(ProductVariantItem::class);
    }

    public function batches()
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function serials()
    {
        return $this->hasMany(ProductSerial::class);
    }
}
