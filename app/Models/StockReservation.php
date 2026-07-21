<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    protected $table = 'stock_reservations';
    protected $guarded = [];

    protected $casts = [
        'reserved_quantity' => 'decimal:3',
        'fulfilled_quantity' => 'decimal:3',
        'released_quantity' => 'decimal:3',
        'expires_at' => 'datetime',
    ];

    public function product() { return $this->belongsTo(Product::class); }
}
