<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryStockStatus extends Model
{
    protected $table = 'inventory_stock_statuses';
    protected $guarded = [];

    protected $casts = ['is_saleable' => 'boolean', 'is_system' => 'boolean'];
}
