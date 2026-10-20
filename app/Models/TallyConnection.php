<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TallyConnection extends Model
{
    protected $guarded = [];
    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings_json' => 'array',
        'pairing_expires_at' => 'datetime',
        'connector_token_issued_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function batches() { return $this->hasMany(TallySyncBatch::class); }
}
