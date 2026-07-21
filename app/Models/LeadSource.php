<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeadSource extends Model
{
    use SoftDeletes;
    protected $table = 'lead_sources';
    protected $guarded = [];
    protected $casts = ['cost_tracking_enabled' => 'boolean', 'is_system' => 'boolean'];
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function leads() { return $this->hasMany(Lead::class); }
}
