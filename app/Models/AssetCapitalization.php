<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetCapitalization extends Model
{
    protected $table = 'asset_capitalizations';
    protected $guarded = [];
    protected $casts = ['capitalization_date' => 'date', 'put_to_use_date' => 'date', 'capitalized_amount' => 'decimal:2', 'approved_at' => 'datetime'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
    public function category() { return $this->belongsTo(AssetCategory::class, 'asset_category_id'); }
    public function location() { return $this->belongsTo(AssetLocation::class, 'asset_location_id'); }
}
