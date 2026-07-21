<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $table = 'attendance_records';
    protected $guarded = [];
    protected $casts = ['attendance_date' => 'date', 'first_in_at' => 'datetime', 'last_out_at' => 'datetime', 'locked' => 'boolean'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function shift() { return $this->belongsTo(Shift::class); }
    public function punches() { return $this->hasMany(AttendancePunch::class); }
}
