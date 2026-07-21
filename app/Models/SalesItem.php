<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesItem extends Model
{
    protected $table = 'sales_items';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'free_quantity' => 'decimal:3',
        'selling_rate' => 'decimal:2',
        'mrp' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'cgst_rate' => 'decimal:2',
        'sgst_rate' => 'decimal:2',
        'igst_rate' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'cess_rate' => 'decimal:2',
        'cess_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'cost_rate' => 'decimal:2',
    ];

    public function voucher()
    {
        return $this->belongsTo(SalesVoucher::class, 'sales_voucher_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariantItem::class, 'product_variant_id');
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
