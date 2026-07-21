<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetSetting extends Model
{
    protected $table = 'fixed_asset_settings';
    protected $guarded = [];
    protected $casts = ['allow_backdated_capitalization' => 'boolean', 'allow_manual_depreciation_override' => 'boolean', 'require_asset_tag' => 'boolean', 'auto_generate_asset_tag' => 'boolean', 'require_asset_verification' => 'boolean'];
}
