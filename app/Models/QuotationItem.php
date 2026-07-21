<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    protected $table = 'quotation_items';
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'taxable_amount' => 'decimal:2', 'gst_snapshot' => 'array', 'total' => 'decimal:2'];
    public function quotation() { return $this->belongsTo(Quotation::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
