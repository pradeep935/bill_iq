<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSalaryComponent extends Model
{
    protected $table = 'employee_salary_components';
    protected $guarded = [];
    protected $casts = ['fixed_amount' => 'decimal:2', 'percentage' => 'decimal:4', 'monthly_amount' => 'decimal:2', 'annual_amount' => 'decimal:2', 'overridden' => 'boolean'];

    public function assignment() { return $this->belongsTo(EmployeeSalaryAssignment::class, 'employee_salary_assignment_id'); }
    public function component() { return $this->belongsTo(SalaryComponent::class, 'salary_component_id'); }
}
