<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePayroll extends Model
{
    protected $table = 'employee_payrolls';
    protected $guarded = [];
    protected $casts = ['calendar_days' => 'decimal:2', 'paid_days' => 'decimal:2', 'unpaid_days' => 'decimal:2', 'overtime_hours' => 'decimal:2', 'gross_earnings' => 'decimal:2', 'total_deductions' => 'decimal:2', 'employer_contributions' => 'decimal:2', 'reimbursements' => 'decimal:2', 'net_pay' => 'decimal:2', 'exceptions_json' => 'array', 'payslip_generated_at' => 'datetime'];

    public function run() { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function assignment() { return $this->belongsTo(EmployeeSalaryAssignment::class, 'salary_assignment_id'); }
    public function components() { return $this->hasMany(EmployeePayrollComponent::class); }
}
