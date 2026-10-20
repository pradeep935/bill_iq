<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TallySyncBatch extends Model
{
    protected $guarded = [];
    protected $casts = ['summary_json' => 'array', 'error_json' => 'array', 'approved_at' => 'datetime', 'completed_at' => 'datetime'];
    public function connection() { return $this->belongsTo(TallyConnection::class, 'tally_connection_id'); }
    public function records() { return $this->hasMany(TallySourceRecord::class); }
    public function reconciliationItems() { return $this->hasMany(TallyReconciliationItem::class); }
}
