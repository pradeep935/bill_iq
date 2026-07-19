<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'before_data' => 'array',
        'after_data' => 'array',
    ];
}
