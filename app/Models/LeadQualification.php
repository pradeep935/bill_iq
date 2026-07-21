<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadQualification extends Model
{
    protected $table = 'lead_qualifications';
    protected $guarded = [];
    protected $casts = ['budget_amount' => 'decimal:2', 'expected_purchase_date' => 'date', 'qualified_at' => 'datetime'];
    public function lead() { return $this->belongsTo(Lead::class); }
}
