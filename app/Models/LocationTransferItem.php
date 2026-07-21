<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationTransferItem extends Model
{
    protected $table = 'location_transfer_items';
    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:3'];

    public function voucher() { return $this->belongsTo(LocationTransferVoucher::class, 'location_transfer_voucher_id'); }
    public function product() { return $this->belongsTo(Product::class); }
}
