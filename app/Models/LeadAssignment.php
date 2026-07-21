<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadAssignment extends Model
{
    protected $table = 'lead_assignments';
    protected $guarded = [];
    protected $casts = ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    public function lead() { return $this->belongsTo(Lead::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
}
