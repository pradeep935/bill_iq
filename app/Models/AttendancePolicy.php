<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePolicy extends Model
{
    protected $table = 'attendance_policies';
    protected $guarded = [];
    protected $casts = [
        'late_mark_enabled' => 'boolean', 'early_exit_enabled' => 'boolean', 'half_day_enabled' => 'boolean',
        'overtime_enabled' => 'boolean', 'auto_absent_enabled' => 'boolean',
        'attendance_regularization_enabled' => 'boolean', 'regularization_approval_required' => 'boolean',
    ];
}
