<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    protected $table = 'pipeline_stages';
    protected $guarded = [];
    protected $casts = ['probability_percent' => 'decimal:2', 'is_won' => 'boolean', 'is_lost' => 'boolean'];
    public function pipeline() { return $this->belongsTo(SalesPipeline::class, 'sales_pipeline_id'); }
    public function opportunities() { return $this->hasMany(Opportunity::class, 'stage_id'); }
}
