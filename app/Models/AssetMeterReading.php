<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetMeterReading extends Model
{
    protected $table = 'asset_meter_readings';
    protected $guarded = [];
    protected $casts = ['reading_date' => 'date', 'reading_value' => 'decimal:3'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
