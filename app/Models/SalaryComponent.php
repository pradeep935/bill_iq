<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalaryComponent extends Model
{
    use SoftDeletes;
    protected $table = 'salary_components';
    protected $guarded = [];
    protected $casts = [
        'default_value' => 'decimal:2', 'minimum_value' => 'decimal:2', 'maximum_value' => 'decimal:2',
        'taxable' => 'boolean', 'statutory' => 'boolean', 'recurring' => 'boolean', 'attendance_dependent' => 'boolean',
        'prorate_on_joining' => 'boolean', 'prorate_on_exit' => 'boolean', 'include_in_gross' => 'boolean',
        'include_in_ctc' => 'boolean', 'include_in_net_pay' => 'boolean', 'employer_contribution' => 'boolean', 'is_system' => 'boolean',
    ];

    public function baseComponent() { return $this->belongsTo(self::class, 'percentage_base_component_id'); }
    public function expenseAccount() { return $this->belongsTo(Account::class, 'expense_account_id'); }
    public function payableAccount() { return $this->belongsTo(Account::class, 'payable_account_id'); }
    public function deductionAccount() { return $this->belongsTo(Account::class, 'deduction_account_id'); }
}
