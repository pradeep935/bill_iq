<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeGrade extends Model
{
    protected $table = 'employee_grades';
    protected $guarded = [];
    protected $casts = ['minimum_salary' => 'decimal:2', 'maximum_salary' => 'decimal:2'];

    public function designations() { return $this->hasMany(Designation::class, 'grade_id'); }
    public function salaryStructures() { return $this->hasMany(SalaryStructure::class, 'grade_id'); }
}
