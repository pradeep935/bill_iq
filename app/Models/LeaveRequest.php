<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    protected $table = 'leave_requests';
    protected $guarded = [];
    protected $casts = ['from_date' => 'date', 'to_date' => 'date', 'total_days' => 'decimal:2', 'applied_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function leaveType() { return $this->belongsTo(LeaveType::class); }
}
