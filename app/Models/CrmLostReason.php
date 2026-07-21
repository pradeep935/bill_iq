<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmLostReason extends Model
{
    protected $table = 'crm_lost_reasons';
    protected $guarded = [];
    protected $casts = ['is_system' => 'boolean'];
}
