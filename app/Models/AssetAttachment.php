<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetAttachment extends Model
{
    protected $table = 'asset_attachments';
    protected $guarded = [];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
}
