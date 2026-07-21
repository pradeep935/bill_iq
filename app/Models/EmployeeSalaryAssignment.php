<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSalaryAssignment extends Model
{
    protected $table = 'employee_salary_assignments';
    protected $guarded = [];
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'annual_ctc' => 'decimal:2', 'monthly_gross' => 'decimal:2', 'monthly_net_estimate' => 'decimal:2', 'approved_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function structure() { return $this->belongsTo(SalaryStructure::class, 'salary_structure_id'); }
    public function components() { return $this->hasMany(EmployeeSalaryComponent::class); }
}
