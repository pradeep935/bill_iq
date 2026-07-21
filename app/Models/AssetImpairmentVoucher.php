<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetImpairmentVoucher extends Model
{
    protected $table = 'asset_impairment_vouchers';
    protected $guarded = [];
    protected $casts = ['impairment_date' => 'date', 'carrying_amount_before' => 'decimal:2', 'recoverable_amount' => 'decimal:2', 'impairment_loss' => 'decimal:2'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
