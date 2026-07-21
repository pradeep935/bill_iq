<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    protected $table = 'stock_adjustment_items';
    protected $guarded = [];

    protected $casts = [
        'system_quantity' => 'decimal:3',
        'actual_quantity' => 'decimal:3',
        'adjustment_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'adjustment_value' => 'decimal:2',
    ];

    public function voucher() { return $this->belongsTo(StockAdjustmentVoucher::class, 'stock_adjustment_voucher_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function variant() { return $this->belongsTo(ProductVariantItem::class, 'product_variant_id'); }
    public function batch() { return $this->belongsTo(ProductBatch::class); }
}
