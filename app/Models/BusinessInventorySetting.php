<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessInventorySetting extends Model
{
    protected $table = 'business_inventory_settings';
    protected $guarded = [];

    protected $casts = ['negative_stock_allowed' => 'boolean'];
}
