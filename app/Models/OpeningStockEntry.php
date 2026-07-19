<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningStockEntry extends Model
{
    protected $table = 'opening_stock_entries';
    protected $guarded = [];

    protected $casts = [
        'entry_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(OpeningStockItem::class);
    }
}
