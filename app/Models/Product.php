<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $guarded = [];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'mrp' => 'decimal:2',
        'opening_stock' => 'decimal:3',
        'minimum_stock' => 'decimal:3',
        'reorder_stock' => 'decimal:3',
        'gst_rate' => 'decimal:2',
        'cess_rate' => 'decimal:2',
    ];
}