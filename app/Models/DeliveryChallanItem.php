<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryChallanItem extends Model
{
    protected $table = 'delivery_challan_items';
    protected $guarded = [];
    protected $casts = ['ordered_quantity' => 'decimal:3', 'dispatch_quantity' => 'decimal:3', 'pending_quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'package_snapshot' => 'array'];
    public function challan() { return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id'); }
    public function orderItem() { return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
