<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    protected $table = 'purchase_requisitions';
    protected $guarded = [];
    protected $casts = ['requisition_date' => 'date', 'required_date' => 'date', 'approved_at' => 'datetime'];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function items() { return $this->hasMany(PurchaseRequisitionItem::class); }
}
