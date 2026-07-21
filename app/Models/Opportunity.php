<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Opportunity extends Model
{
    use SoftDeletes;
    protected $table = 'opportunities';
    protected $guarded = [];
    protected $casts = [
        'estimated_value' => 'decimal:2',
        'weighted_value' => 'decimal:2',
        'probability_percent' => 'decimal:2',
        'expected_closing_date' => 'date',
        'actual_closing_date' => 'date',
        'next_follow_up_at' => 'datetime',
    ];
    public function lead() { return $this->belongsTo(Lead::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function pipeline() { return $this->belongsTo(SalesPipeline::class, 'pipeline_id'); }
    public function stage() { return $this->belongsTo(PipelineStage::class, 'stage_id'); }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function team() { return $this->belongsTo(SalesTeam::class, 'sales_team_id'); }
    public function source() { return $this->belongsTo(LeadSource::class, 'source_id'); }
    public function campaign() { return $this->belongsTo(Campaign::class); }
    public function items() { return $this->hasMany(OpportunityItem::class); }
    public function activities() { return $this->hasMany(CrmActivity::class); }
    public function quotation() { return $this->belongsTo(Quotation::class); }
    public function salesOrder() { return $this->belongsTo(SalesOrder::class); }
    public function lostReason() { return $this->belongsTo(CrmLostReason::class, 'lost_reason_id'); }
}
