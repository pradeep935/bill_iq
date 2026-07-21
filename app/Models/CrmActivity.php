<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmActivity extends Model
{
    protected $table = 'crm_activities';
    protected $guarded = [];
    protected $casts = [
        'activity_date' => 'date',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'next_follow_up_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
    public function lead() { return $this->belongsTo(Lead::class); }
    public function opportunity() { return $this->belongsTo(Opportunity::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function reminders() { return $this->hasMany(CrmReminder::class, 'activity_id'); }
}
