<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BarcodeLabelPrint extends Model
{
    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    public function product() { return $this->belongsTo(Product::class); }
    public function barcode() { return $this->belongsTo(ProductBarcode::class, 'product_barcode_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
