<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPaymentBatch extends Model
{
    protected $table = 'payroll_payment_batches';
    protected $guarded = [];
    protected $casts = ['payment_date' => 'date', 'total_amount' => 'decimal:2', 'processed_at' => 'datetime'];

    public function run() { return $this->belongsTo(PayrollRun::class, 'payroll_run_id'); }
    public function payments() { return $this->hasMany(PayrollPayment::class); }
}
