<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBarcode extends Model
{
    protected $table = 'product_barcodes';
    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant() { return $this->belongsTo(ProductVariantItem::class, 'product_variant_id'); }
    public function batch() { return $this->belongsTo(ProductBatch::class); }
    public function serial() { return $this->belongsTo(ProductSerialNumber::class, 'serial_number_id'); }
}
