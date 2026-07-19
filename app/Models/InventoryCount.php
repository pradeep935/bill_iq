<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCount extends Model
{
    protected $table = 'inventory_counts';
    protected $guarded = [];

    protected $casts = [
        'approved_at' => 'datetime',
    ];
}
