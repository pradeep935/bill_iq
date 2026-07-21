<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLoan extends Model
{
    protected $table = 'employee_loans';
    protected $guarded = [];
    protected $casts = ['loan_date' => 'date', 'principal_amount' => 'decimal:2', 'interest_rate' => 'decimal:4', 'emi_amount' => 'decimal:2', 'recovered_principal' => 'decimal:2', 'recovered_interest' => 'decimal:2', 'outstanding_amount' => 'decimal:2'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function installments() { return $this->hasMany(EmployeeLoanInstallment::class); }
}
