<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;
    protected $table = 'leads';
    protected $guarded = [];
    protected $casts = [
        'estimated_value' => 'decimal:2',
        'expected_closing_date' => 'date',
        'preferred_product_ids_json' => 'array',
        'billing_address_json' => 'array',
        'shipping_address_json' => 'array',
        'do_not_call' => 'boolean',
        'do_not_email' => 'boolean',
        'do_not_whatsapp' => 'boolean',
        'consent_at' => 'datetime',
        'converted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
        'tags_json' => 'array',
        'custom_fields_json' => 'array',
    ];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function source() { return $this->belongsTo(LeadSource::class, 'lead_source_id'); }
    public function campaign() { return $this->belongsTo(Campaign::class); }
    public function statusModel() { return $this->belongsTo(LeadStatus::class, 'status_id'); }
    public function owner() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function team() { return $this->belongsTo(SalesTeam::class, 'assigned_team_id'); }
    public function contacts() { return $this->hasMany(LeadContact::class); }
    public function assignments() { return $this->hasMany(LeadAssignment::class); }
    public function opportunities() { return $this->hasMany(Opportunity::class); }
    public function activities() { return $this->hasMany(CrmActivity::class); }
    public function notes() { return $this->morphMany(CrmNote::class, 'related'); }
    public function convertedCustomer() { return $this->belongsTo(Customer::class, 'converted_customer_id'); }
    public function lostReason() { return $this->belongsTo(CrmLostReason::class, 'lost_reason_id'); }
    public function qualification() { return $this->hasOne(LeadQualification::class); }
}
