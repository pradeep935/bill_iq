<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisitionItem extends Model
{
    protected $table = 'purchase_requisition_items';
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:3', 'approved_quantity' => 'decimal:3'];
    public function requisition() { return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
