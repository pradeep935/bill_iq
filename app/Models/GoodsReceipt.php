<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    protected $table = 'goods_receipts';
    protected $guarded = [];
    protected $casts = ['receipt_date' => 'date', 'received_at' => 'datetime'];
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function items() { return $this->hasMany(GoodsReceiptItem::class); }
}
