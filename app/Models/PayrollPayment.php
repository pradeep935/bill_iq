<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPayment extends Model
{
    protected $table = 'payroll_payments';
    protected $guarded = [];
    protected $casts = ['payable_amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function batch() { return $this->belongsTo(PayrollPaymentBatch::class, 'payroll_payment_batch_id'); }
    public function employeePayroll() { return $this->belongsTo(EmployeePayroll::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
}
