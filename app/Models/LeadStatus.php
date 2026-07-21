<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadStatus extends Model
{
    protected $table = 'lead_statuses';
    protected $guarded = [];
    protected $casts = ['is_initial' => 'boolean', 'is_converted' => 'boolean', 'is_lost' => 'boolean', 'is_system' => 'boolean', 'active' => 'boolean'];
    public function leads() { return $this->hasMany(Lead::class, 'status_id'); }
}
