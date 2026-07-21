<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollAdjustment extends Model
{
    protected $table = 'payroll_adjustments';
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'approved_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function run() { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
}
