<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderItem extends Model
{
    protected $table = 'sales_order_items';
    protected $guarded = [];
    protected $casts = ['ordered_quantity' => 'decimal:3', 'reserved_quantity' => 'decimal:3', 'delivered_quantity' => 'decimal:3', 'invoiced_quantity' => 'decimal:3', 'cancelled_quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'tax_snapshot' => 'array', 'line_total' => 'decimal:2'];
    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
