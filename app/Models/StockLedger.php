<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockLedger extends Model
{
    protected $table = 'stock_ledgers';
    protected $guarded = [];

    protected $casts = [
        'transaction_date' => 'datetime',
    ];
}
