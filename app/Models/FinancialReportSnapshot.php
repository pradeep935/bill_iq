<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialReportSnapshot extends Model
{
    protected $table = 'financial_report_snapshots';
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'snapshot_json' => 'array',
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
    ];
}
