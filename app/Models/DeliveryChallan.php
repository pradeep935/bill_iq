<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryChallan extends Model
{
    protected $table = 'delivery_challans';
    protected $guarded = [];
    protected $casts = ['challan_date' => 'date', 'dispatch_checklist_json' => 'array', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function items() { return $this->hasMany(DeliveryChallanItem::class); }
}
