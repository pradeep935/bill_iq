<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmNote extends Model
{
    use SoftDeletes;
    protected $table = 'crm_notes';
    protected $guarded = [];
    protected $casts = ['is_pinned' => 'boolean'];
}
