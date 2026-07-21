<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicyRule extends Model
{
    protected $table = 'leave_policy_rules';
    protected $guarded = [];
    protected $casts = ['annual_entitlement' => 'decimal:2', 'accrual_amount' => 'decimal:2', 'carry_forward_limit' => 'decimal:2', 'encashment_limit' => 'decimal:2'];

    public function policy() { return $this->belongsTo(LeavePolicy::class, 'leave_policy_id'); }
    public function leaveType() { return $this->belongsTo(LeaveType::class); }
}
