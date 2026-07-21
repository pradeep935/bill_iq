<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerAllocation extends Model
{
    protected $table = 'ledger_allocations';
    protected $guarded = [];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'write_off_amount' => 'decimal:2',
        'allocation_date' => 'date',
    ];
}
