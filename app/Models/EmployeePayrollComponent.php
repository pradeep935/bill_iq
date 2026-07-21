<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeePayrollComponent extends Model
{
    protected $table = 'employee_payroll_components';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'statutory' => 'boolean', 'employer_contribution' => 'boolean', 'snapshot_json' => 'array'];

    public function employeePayroll() { return $this->belongsTo(EmployeePayroll::class); }
    public function component() { return $this->belongsTo(SalaryComponent::class, 'salary_component_id'); }
}
