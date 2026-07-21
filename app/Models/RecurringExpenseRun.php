<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecurringExpenseRun extends Model
{
    protected $table = 'recurring_expense_runs';
    protected $guarded = [];

    protected $casts = [
        'run_date' => 'date',
    ];
}
