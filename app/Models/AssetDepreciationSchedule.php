<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDepreciationSchedule extends Model
{
    protected $table = 'asset_depreciation_schedules';
    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'opening_gross_value' => 'decimal:2', 'opening_accumulated_depreciation' => 'decimal:2', 'depreciation_amount' => 'decimal:2', 'adjustment_amount' => 'decimal:2', 'closing_accumulated_depreciation' => 'decimal:2', 'closing_net_book_value' => 'decimal:2', 'calculated_at' => 'datetime', 'posted_at' => 'datetime'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
    public function run() { return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id'); }
}
