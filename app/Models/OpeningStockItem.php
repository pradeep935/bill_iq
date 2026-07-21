<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningStockItem extends Model
{
    protected $table = 'opening_stock_items';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:3',
        'purchase_cost' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'mrp' => 'decimal:2',
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
    ];

    public function voucher()
    {
        return $this->belongsTo(OpeningStockVoucher::class, 'opening_stock_voucher_id');
    }

    public function entry()
    {
        return $this->belongsTo(OpeningStockEntry::class, 'opening_stock_entry_id');
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
}
