<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetMaintenanceRequest extends Model
{
    protected $table = 'asset_maintenance_requests';
    protected $guarded = [];
    protected $casts = ['request_date' => 'date', 'expected_start_date' => 'date', 'expected_completion_date' => 'date', 'actual_start_date' => 'date', 'actual_completion_date' => 'date', 'estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2', 'downtime_hours' => 'decimal:2'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
    public function vendor() { return $this->belongsTo(Supplier::class, 'assigned_vendor_id'); }
}
