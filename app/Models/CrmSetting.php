<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmSetting extends Model
{
    protected $table = 'crm_settings';
    protected $guarded = [];
    protected $casts = [
        'auto_assign_leads' => 'boolean',
        'require_lead_source' => 'boolean',
        'require_lost_reason' => 'boolean',
        'duplicate_check_enabled' => 'boolean',
        'duplicate_check_fields_json' => 'array',
        'overdue_reminder_enabled' => 'boolean',
        'lead_conversion_requires_approval' => 'boolean',
        'allow_multiple_opportunities_per_lead' => 'boolean',
    ];
}
