<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialReportException extends Model
{
    protected $table = 'financial_report_exceptions';
    protected $guarded = [];

    protected $casts = ['audit_json' => 'array'];
}
