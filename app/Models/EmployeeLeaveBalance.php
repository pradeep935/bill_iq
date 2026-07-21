<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLeaveBalance extends Model
{
    protected $table = 'employee_leave_balances';
    protected $guarded = [];
    protected $casts = ['opening_balance' => 'decimal:2', 'accrued' => 'decimal:2', 'used' => 'decimal:2', 'adjusted' => 'decimal:2', 'encashed' => 'decimal:2', 'lapsed' => 'decimal:2', 'closing_balance' => 'decimal:2'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function leaveType() { return $this->belongsTo(LeaveType::class); }
}
