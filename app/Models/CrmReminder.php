<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmReminder extends Model
{
    protected $table = 'crm_reminders';
    protected $guarded = [];
    protected $casts = ['reminder_at' => 'datetime', 'sent_at' => 'datetime', 'snoozed_until' => 'datetime'];
    public function activity() { return $this->belongsTo(CrmActivity::class); }
}
