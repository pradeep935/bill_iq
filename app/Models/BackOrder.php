<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackOrder extends Model
{
    protected $table = 'back_orders';
    protected $guarded = [];
    protected $casts = ['pending_quantity' => 'decimal:3', 'expected_date' => 'date'];
}
