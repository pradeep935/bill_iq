<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FixedAsset extends Model
{
    use SoftDeletes;
    protected $table = 'fixed_assets';
    protected $guarded = [];
    protected $casts = [
        'acquisition_date' => 'date', 'capitalization_date' => 'date', 'put_to_use_date' => 'date',
        'purchase_cost' => 'decimal:2', 'additional_cost' => 'decimal:2', 'capitalized_cost' => 'decimal:2',
        'residual_value' => 'decimal:2', 'depreciable_amount' => 'decimal:2', 'depreciation_rate' => 'decimal:4',
        'accumulated_depreciation' => 'decimal:2', 'accumulated_impairment' => 'decimal:2', 'net_book_value' => 'decimal:2',
        'warranty_start_date' => 'date', 'warranty_end_date' => 'date', 'expected_disposal_date' => 'date', 'disposal_date' => 'date',
        'approved_at' => 'datetime',
    ];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function category() { return $this->belongsTo(AssetCategory::class, 'asset_category_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function location() { return $this->belongsTo(AssetLocation::class, 'current_location_id'); }
    public function components() { return $this->hasMany(FixedAssetComponent::class); }
    public function schedules() { return $this->hasMany(AssetDepreciationSchedule::class); }
    public function assignments() { return $this->hasMany(AssetAssignment::class); }
    public function maintenanceRequests() { return $this->hasMany(AssetMaintenanceRequest::class); }
    public function warranties() { return $this->hasMany(AssetWarranty::class); }
    public function meterReadings() { return $this->hasMany(AssetMeterReading::class); }
    public function disposals() { return $this->hasMany(AssetDisposalVoucher::class); }
}
