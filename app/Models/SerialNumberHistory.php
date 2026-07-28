<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SerialNumberHistory extends Model
{
    protected $guarded = [];

    public function serial() { return $this->belongsTo(ProductSerialNumber::class, 'serial_number_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
