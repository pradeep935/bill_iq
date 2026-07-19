<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCountScan extends Model
{
    protected $table = 'inventory_count_scans';
    protected $guarded = [];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];
}
