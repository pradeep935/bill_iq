<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Designation extends Model
{
    use SoftDeletes;
    protected $table = 'designations';
    protected $guarded = [];

    public function grade() { return $this->belongsTo(EmployeeGrade::class, 'grade_id'); }
    public function employees() { return $this->hasMany(Employee::class); }
}
