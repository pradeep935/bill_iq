<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    protected $table = 'stock_transfer_items';
    protected $guarded = [];

    protected $casts = [
        'requested_quantity' => 'decimal:3',
        'approved_quantity' => 'decimal:3',
        'dispatched_quantity' => 'decimal:3',
        'received_quantity' => 'decimal:3',
        'rejected_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
    ];

    public function voucher() { return $this->belongsTo(StockTransferVoucher::class, 'stock_transfer_voucher_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function sourceBatch() { return $this->belongsTo(ProductBatch::class, 'source_batch_id'); }
}
