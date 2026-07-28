<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductSerialNumber extends Model
{
    use SoftDeletes;

    protected $table = 'product_serial_numbers';
    protected $guarded = [];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expiry_date' => 'date',
        'sold_at' => 'datetime',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function variant() { return $this->belongsTo(ProductVariantItem::class, 'product_variant_id'); }
    public function batch() { return $this->belongsTo(ProductBatch::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
