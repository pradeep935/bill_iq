<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPriceList extends Model
{
    protected $table = 'customer_price_lists';
    protected $guarded = [];
    protected $casts = ['min_quantity' => 'decimal:3', 'price' => 'decimal:2', 'starts_at' => 'date', 'ends_at' => 'date'];
}
