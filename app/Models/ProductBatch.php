<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBatch extends Model
{
    protected $table = 'product_batches';
    protected $guarded = [];

    protected $casts = [
        'manufacturing_date' => 'date',
        'mfg_date' => 'date',
        'expiry_date' => 'date',
        'purchase_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'quantity' => 'decimal:3',
        'stock_qty' => 'decimal:3',
        'blocked_at' => 'datetime',
        'quarantined_at' => 'datetime',
        'released_at' => 'datetime',
        'unblocked_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function isSaleEligible(): bool
    {
        return $this->status === 'active'
            && !in_array($this->condition_status, ['damaged', 'expired', 'quarantined', 'defective', 'lost', 'return_to_supplier'], true)
            && (!$this->expiry_date || !$this->expiry_date->lt(now()->startOfDay()));
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
