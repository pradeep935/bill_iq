<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TallyMapping extends Model
{
    protected $guarded = [];
    protected $casts = ['evidence_json' => 'array', 'approved_at' => 'datetime', 'confidence' => 'decimal:2'];
}
