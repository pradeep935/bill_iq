<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSettlement extends Model
{
    protected $table = 'employee_settlements';
    protected $guarded = [];
    protected $casts = ['resignation_date' => 'date', 'last_working_date' => 'date', 'settlement_date' => 'date', 'payable_days' => 'decimal:2', 'net_settlement' => 'decimal:2'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
