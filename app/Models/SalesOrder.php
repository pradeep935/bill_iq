<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    protected $table = 'sales_orders';
    protected $guarded = [];
    protected $casts = ['order_date' => 'date', 'expected_delivery_date' => 'date', 'approved_at' => 'datetime'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function quotation() { return $this->belongsTo(Quotation::class); }
    public function items() { return $this->hasMany(SalesOrderItem::class); }
    public function challans() { return $this->hasMany(DeliveryChallan::class); }
}
