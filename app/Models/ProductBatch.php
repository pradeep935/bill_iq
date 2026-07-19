<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBatch extends Model
{
    protected $table = 'product_batches';
    protected $guarded = [];

    protected $casts = [
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
    ];
}
