<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepreciationRun extends Model
{
    protected $table = 'depreciation_runs';
    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'posting_date' => 'date', 'total_depreciation' => 'decimal:2', 'error_summary_json' => 'array', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    public function schedules() { return $this->hasMany(AssetDepreciationSchedule::class); }
}
