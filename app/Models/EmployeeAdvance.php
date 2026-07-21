<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAdvance extends Model
{
    protected $table = 'employee_advances';
    protected $guarded = [];
    protected $casts = ['advance_date' => 'date', 'amount' => 'decimal:2', 'recovered_amount' => 'decimal:2', 'outstanding_amount' => 'decimal:2'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
