<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $table = 'leave_types';
    protected $guarded = [];
    protected $casts = ['paid' => 'boolean', 'carry_forward_allowed' => 'boolean', 'encashment_allowed' => 'boolean', 'negative_balance_allowed' => 'boolean', 'half_day_allowed' => 'boolean', 'probation_eligible' => 'boolean', 'sandwich_rule_enabled' => 'boolean'];

    public function requests() { return $this->hasMany(LeaveRequest::class); }
}
