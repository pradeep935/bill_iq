<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetTransferItem extends Model
{
    protected $table = 'asset_transfer_items';
    protected $guarded = [];
    public function voucher() { return $this->belongsTo(AssetTransferVoucher::class, 'asset_transfer_voucher_id'); }
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
