<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsnTaxRate extends Model
{
    protected $table = 'hsn_tax_rates';
    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function hsn()
    {
        return $this->belongsTo(HsnMaster::class, 'hsn_id');
    }
}
