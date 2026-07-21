<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetTransferVoucher extends Model
{
    protected $table = 'asset_transfer_vouchers';
    protected $guarded = [];
    protected $casts = ['transfer_date' => 'date', 'dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    public function items() { return $this->hasMany(AssetTransferItem::class); }
    public function sourceBranch() { return $this->belongsTo(Branch::class, 'source_branch_id'); }
    public function destinationBranch() { return $this->belongsTo(Branch::class, 'destination_branch_id'); }
    public function sourceLocation() { return $this->belongsTo(AssetLocation::class, 'source_location_id'); }
    public function destinationLocation() { return $this->belongsTo(AssetLocation::class, 'destination_location_id'); }
}
