<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TallySourceRecord extends Model
{
    protected $guarded = [];
    protected $casts = ['payload_json' => 'array', 'validation_json' => 'array', 'source_date' => 'date', 'imported_at' => 'datetime', 'debit_amount' => 'decimal:2', 'credit_amount' => 'decimal:2', 'quantity' => 'decimal:4'];
}
