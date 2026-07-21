<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLoanInstallment extends Model
{
    protected $table = 'employee_loan_installments';
    protected $guarded = [];
    protected $casts = ['due_date' => 'date', 'principal_amount' => 'decimal:2', 'interest_amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];

    public function loan() { return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id'); }
}
