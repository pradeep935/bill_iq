<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetVerificationSession extends Model
{
    protected $table = 'asset_verification_sessions';
    protected $guarded = [];
    protected $casts = ['verification_date' => 'date', 'completed_at' => 'datetime'];
    public function items() { return $this->hasMany(AssetVerificationItem::class, 'asset_verification_session_id'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function location() { return $this->belongsTo(AssetLocation::class, 'location_id'); }
}
