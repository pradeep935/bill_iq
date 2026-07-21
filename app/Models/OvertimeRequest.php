<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvertimeRequest extends Model
{
    protected $table = 'overtime_requests';
    protected $guarded = [];
    protected $casts = ['overtime_date' => 'date', 'rate_multiplier' => 'decimal:4', 'amount' => 'decimal:2', 'approved_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }
}
