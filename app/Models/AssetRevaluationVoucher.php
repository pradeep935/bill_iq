<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetRevaluationVoucher extends Model
{
    protected $table = 'asset_revaluation_vouchers';
    protected $guarded = [];
    protected $casts = ['revaluation_date' => 'date', 'previous_gross_value' => 'decimal:2', 'previous_accumulated_depreciation' => 'decimal:2', 'previous_net_book_value' => 'decimal:2', 'revalued_amount' => 'decimal:2', 'revaluation_difference' => 'decimal:2'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
