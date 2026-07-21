<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferVoucher extends Model
{
    protected $table = 'stock_transfer_vouchers';
    protected $guarded = [];

    protected $casts = [
        'transfer_date' => 'date',
        'expected_delivery_date' => 'date',
        'approved_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function sourceBranch() { return $this->belongsTo(Branch::class, 'source_branch_id'); }
    public function sourceWarehouse() { return $this->belongsTo(Warehouse::class, 'source_warehouse_id'); }
    public function destinationBranch() { return $this->belongsTo(Branch::class, 'destination_branch_id'); }
    public function destinationWarehouse() { return $this->belongsTo(Warehouse::class, 'destination_warehouse_id'); }
    public function items() { return $this->hasMany(StockTransferItem::class); }
}
