<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetVerificationItem extends Model
{
    protected $table = 'asset_verification_items';
    protected $guarded = [];
    protected $casts = ['scanned_at' => 'datetime'];
    public function session() { return $this->belongsTo(AssetVerificationSession::class, 'asset_verification_session_id'); }
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
