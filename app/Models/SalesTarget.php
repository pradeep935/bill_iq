<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTarget extends Model
{
    protected $table = 'sales_targets';
    protected $guarded = [];
    protected $casts = ['period_start' => 'date', 'period_end' => 'date', 'target_quotation_value' => 'decimal:2', 'target_order_value' => 'decimal:2', 'target_invoice_value' => 'decimal:2', 'target_collection_value' => 'decimal:2'];
}
