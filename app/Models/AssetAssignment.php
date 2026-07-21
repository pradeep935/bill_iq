<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetAssignment extends Model
{
    protected $table = 'asset_assignments';
    protected $guarded = [];
    protected $casts = ['assignment_date' => 'date', 'expected_return_date' => 'date', 'actual_return_date' => 'date'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
    public function location() { return $this->belongsTo(AssetLocation::class, 'assigned_location_id'); }
}
