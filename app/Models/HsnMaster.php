<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsnMaster extends Model
{
    protected $table = 'hsn_masters';
    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'classification_verified' => 'boolean',
        'rate_verified' => 'boolean',
    ];

    public function taxRates()
    {
        return $this->hasMany(HsnTaxRate::class, 'hsn_id');
    }

    public function currentTaxRate($date = null)
    {
        $date ??= now()->toDateString();

        return $this->taxRates()
            ->where('status', 'active')
            ->where('verification_status', 'verified')
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->latest('effective_from')
            ->first();
    }
}
