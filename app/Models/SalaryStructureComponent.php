<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryStructureComponent extends Model
{
    protected $table = 'salary_structure_components';
    protected $guarded = [];
    protected $casts = ['fixed_amount' => 'decimal:2', 'percentage' => 'decimal:4', 'annual_amount' => 'decimal:2', 'monthly_amount' => 'decimal:2'];

    public function structure() { return $this->belongsTo(SalaryStructure::class, 'salary_structure_id'); }
    public function component() { return $this->belongsTo(SalaryComponent::class, 'salary_component_id'); }
}
