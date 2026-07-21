<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeShiftAssignment extends Model
{
    protected $table = 'employee_shift_assignments';
    protected $guarded = [];
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function shift() { return $this->belongsTo(Shift::class); }
}
