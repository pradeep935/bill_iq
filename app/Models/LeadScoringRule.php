<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadScoringRule extends Model
{
    protected $table = 'lead_scoring_rules';
    protected $guarded = [];
    protected $casts = ['condition_json' => 'array', 'valid_from' => 'date', 'valid_to' => 'date'];
}
