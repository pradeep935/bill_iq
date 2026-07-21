<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';
    protected $guarded = [];
    protected $casts = ['po_date' => 'date', 'expected_delivery_date' => 'date', 'approved_at' => 'datetime'];
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function requisition() { return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id'); }
    public function items() { return $this->hasMany(PurchaseOrderItem::class); }
}
