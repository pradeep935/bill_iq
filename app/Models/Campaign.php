<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    protected $table = 'campaigns';
    protected $guarded = [];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'budget_amount' => 'decimal:2', 'actual_cost' => 'decimal:2', 'target_revenue' => 'decimal:2'];
    public function leads() { return $this->hasMany(Lead::class); }
    public function opportunities() { return $this->hasMany(Opportunity::class); }
}
