<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;
    protected $table = 'employees';
    protected $guarded = [];

    protected $casts = [
        'date_of_birth' => 'date', 'joining_date' => 'date', 'confirmation_date' => 'date',
        'probation_end_date' => 'date', 'resignation_date' => 'date', 'last_working_date' => 'date',
        'retirement_date' => 'date', 'current_address_json' => 'array', 'permanent_address_json' => 'array',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function designation() { return $this->belongsTo(Designation::class); }
    public function manager() { return $this->belongsTo(self::class, 'reporting_manager_id'); }
    public function documents() { return $this->hasMany(EmployeeDocument::class); }
    public function salaryAssignments() { return $this->hasMany(EmployeeSalaryAssignment::class); }
    public function activeSalaryAssignment() { return $this->hasOne(EmployeeSalaryAssignment::class)->where('status', 'active')->latest('effective_from'); }
    public function attendanceRecords() { return $this->hasMany(AttendanceRecord::class); }
    public function leaveRequests() { return $this->hasMany(LeaveRequest::class); }
    public function payrolls() { return $this->hasMany(EmployeePayroll::class); }
    public function advances() { return $this->hasMany(EmployeeAdvance::class); }
    public function loans() { return $this->hasMany(EmployeeLoan::class); }
}
