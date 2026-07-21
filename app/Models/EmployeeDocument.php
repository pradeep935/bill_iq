<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDocument extends Model
{
    use SoftDeletes;
    protected $table = 'employee_documents';
    protected $guarded = [];
    protected $casts = ['issue_date' => 'date', 'expiry_date' => 'date', 'verified_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
