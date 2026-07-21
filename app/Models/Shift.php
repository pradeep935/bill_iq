<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $table = 'shifts';
    protected $guarded = [];
    protected $casts = ['night_shift' => 'boolean', 'crosses_midnight' => 'boolean'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function assignments() { return $this->hasMany(EmployeeShiftAssignment::class); }
}
