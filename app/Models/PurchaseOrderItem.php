<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';
    protected $guarded = [];
    protected $casts = ['ordered_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'rejected_quantity' => 'decimal:3', 'returned_quantity' => 'decimal:3', 'purchase_rate' => 'decimal:2', 'tax_amount' => 'decimal:2', 'tax_snapshot' => 'array', 'line_total' => 'decimal:2'];
    public function order() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
