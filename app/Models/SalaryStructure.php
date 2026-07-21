<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryStructure extends Model
{
    use SoftDeletes;
    protected $table = 'salary_structures';
    protected $guarded = [];
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'annual_ctc' => 'decimal:2', 'monthly_gross' => 'decimal:2', 'approved_at' => 'datetime'];

    public function grade() { return $this->belongsTo(EmployeeGrade::class, 'grade_id'); }
    public function components() { return $this->hasMany(SalaryStructureComponent::class); }
    public function assignments() { return $this->hasMany(EmployeeSalaryAssignment::class); }
}
