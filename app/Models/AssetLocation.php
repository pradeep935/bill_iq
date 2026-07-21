<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetLocation extends Model
{
    use SoftDeletes;
    protected $table = 'asset_locations';
    protected $guarded = [];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function assets() { return $this->hasMany(FixedAsset::class, 'current_location_id'); }
}
