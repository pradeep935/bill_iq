<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetComponent extends Model
{
    protected $table = 'fixed_asset_components';
    protected $guarded = [];
    protected $casts = ['capitalization_date' => 'date', 'component_cost' => 'decimal:2', 'residual_value' => 'decimal:2', 'depreciation_rate' => 'decimal:4', 'accumulated_depreciation' => 'decimal:2', 'net_book_value' => 'decimal:2'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
