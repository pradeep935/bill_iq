<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use SoftDeletes;
    protected $table = 'departments';
    protected $guarded = [];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function head() { return $this->belongsTo(Employee::class, 'department_head_id'); }
    public function employees() { return $this->hasMany(Employee::class); }
}
