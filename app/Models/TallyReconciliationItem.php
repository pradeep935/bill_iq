<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TallyReconciliationItem extends Model
{
    protected $guarded = [];
    protected $casts = ['details_json' => 'array', 'tally_amount' => 'decimal:2', 'billiq_amount' => 'decimal:2', 'difference_amount' => 'decimal:2'];
}
