<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $table = 'quotations';
    protected $guarded = [];
    protected $casts = ['quotation_date' => 'date', 'valid_until' => 'date', 'customer_snapshot_json' => 'array', 'approved_at' => 'datetime'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function items() { return $this->hasMany(QuotationItem::class); }
    public function salesOrder() { return $this->belongsTo(SalesOrder::class, 'converted_sales_order_id'); }
}
