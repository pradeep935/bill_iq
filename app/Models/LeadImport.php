<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadImport extends Model
{
    protected $table = 'lead_imports';
    protected $guarded = [];
    protected $casts = ['mapping_json' => 'array'];
}
