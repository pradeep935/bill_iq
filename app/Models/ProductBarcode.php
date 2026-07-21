<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBarcode extends Model
{
    protected $table = 'product_barcodes';
    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
