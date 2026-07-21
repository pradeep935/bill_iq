<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePunch extends Model
{
    protected $table = 'attendance_punches';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['punch_at' => 'datetime', 'raw_payload_json' => 'array', 'created_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function attendanceRecord() { return $this->belongsTo(AttendanceRecord::class); }
}
