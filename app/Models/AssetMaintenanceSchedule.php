<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetMaintenanceSchedule extends Model
{
    protected $table = 'asset_maintenance_schedules';
    protected $guarded = [];
    protected $casts = ['last_service_date' => 'date', 'next_service_date' => 'date', 'trigger_meter_value' => 'decimal:3', 'current_meter_value' => 'decimal:3', 'auto_create_request' => 'boolean'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
