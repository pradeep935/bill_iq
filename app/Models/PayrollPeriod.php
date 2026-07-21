<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    protected $table = 'payroll_periods';
    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'payment_date' => 'date'];

    public function runs() { return $this->hasMany(PayrollRun::class); }
}
