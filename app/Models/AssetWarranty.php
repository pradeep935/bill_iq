<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetWarranty extends Model
{
    protected $table = 'asset_warranties';
    protected $guarded = [];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
