<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceiptItem extends Model
{
    protected $table = 'goods_receipt_items';
    protected $guarded = [];
    protected $casts = ['ordered_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'rejected_quantity' => 'decimal:3', 'damaged_quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];
    public function receipt() { return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id'); }
    public function orderItem() { return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
