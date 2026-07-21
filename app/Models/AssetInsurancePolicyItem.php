<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetInsurancePolicyItem extends Model
{
    protected $table = 'asset_insurance_policy_items';
    protected $guarded = [];
    protected $casts = ['insured_value' => 'decimal:2'];
    public function policy() { return $this->belongsTo(AssetInsurancePolicy::class, 'asset_insurance_policy_id'); }
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
