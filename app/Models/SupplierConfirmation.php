<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierConfirmation extends Model
{
    protected $table = 'supplier_confirmations';
    protected $guarded = [];
    protected $casts = ['expected_delivery_date' => 'date', 'modified_items_json' => 'array'];
}
