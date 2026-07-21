<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $table = 'payroll_runs';
    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'payment_date' => 'date', 'gross_earnings' => 'decimal:2', 'total_deductions' => 'decimal:2', 'employer_contributions' => 'decimal:2', 'reimbursements' => 'decimal:2', 'net_pay' => 'decimal:2', 'approved_at' => 'datetime'];

    public function period() { return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function employeePayrolls() { return $this->hasMany(EmployeePayroll::class); }
}
