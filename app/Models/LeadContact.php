<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadContact extends Model
{
    protected $table = 'lead_contacts';
    protected $guarded = [];
    protected $casts = ['is_primary' => 'boolean', 'do_not_call' => 'boolean', 'do_not_email' => 'boolean', 'do_not_whatsapp' => 'boolean'];
    public function lead() { return $this->belongsTo(Lead::class); }
}
