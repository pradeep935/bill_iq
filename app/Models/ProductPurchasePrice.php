<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPurchasePrice extends Model
{
    protected $table = 'product_purchase_prices';
    protected $guarded = [];

    protected $casts = [
        'purchase_date' => 'date',
    ];
}
