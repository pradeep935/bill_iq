<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    protected $table = 'payroll_settings';
    protected $guarded = [];

    protected $casts = [
        'attendance_integration_enabled' => 'boolean',
        'leave_integration_enabled' => 'boolean',
        'overtime_enabled' => 'boolean',
        'negative_salary_allowed' => 'boolean',
        'payroll_approval_required' => 'boolean',
        'auto_post_payroll_accounting' => 'boolean',
    ];
}
