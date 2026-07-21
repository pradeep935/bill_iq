<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetInsurancePolicy extends Model
{
    protected $table = 'asset_insurance_policies';
    protected $guarded = [];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'insured_value' => 'decimal:2', 'premium_amount' => 'decimal:2', 'deductible_amount' => 'decimal:2'];
    public function items() { return $this->hasMany(AssetInsurancePolicyItem::class); }
}
