<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadScoreLog extends Model
{
    protected $table = 'lead_score_logs';
    protected $guarded = [];
    public function lead() { return $this->belongsTo(Lead::class); }
    public function rule() { return $this->belongsTo(LeadScoringRule::class, 'scoring_rule_id'); }
}
