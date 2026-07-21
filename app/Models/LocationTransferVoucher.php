<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationTransferVoucher extends Model
{
    protected $table = 'location_transfer_vouchers';
    protected $guarded = [];

    protected $casts = ['movement_date' => 'date'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function items() { return $this->hasMany(LocationTransferItem::class); }
}
