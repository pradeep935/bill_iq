<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollStatutoryLiability extends Model
{
    protected $table = 'payroll_statutory_liabilities';
    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'employee_contribution' => 'decimal:2', 'employer_contribution' => 'decimal:2', 'total_payable' => 'decimal:2', 'paid_amount' => 'decimal:2', 'outstanding_amount' => 'decimal:2', 'due_date' => 'date', 'payment_date' => 'date'];

    public function run() { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
}
