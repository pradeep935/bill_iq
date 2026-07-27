<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLocation extends Model
{
    protected $table = 'warehouse_locations';
    protected $guarded = [];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
