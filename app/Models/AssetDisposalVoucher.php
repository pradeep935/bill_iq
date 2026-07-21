<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDisposalVoucher extends Model
{
    protected $table = 'asset_disposal_vouchers';
    protected $guarded = [];
    protected $casts = ['disposal_date' => 'date', 'sale_value' => 'decimal:2', 'tax_amount' => 'decimal:2', 'disposal_expense' => 'decimal:2', 'gross_book_value' => 'decimal:2', 'accumulated_depreciation' => 'decimal:2', 'accumulated_impairment' => 'decimal:2', 'net_book_value' => 'decimal:2', 'profit_or_loss' => 'decimal:2', 'posted_at' => 'datetime'];
    public function asset() { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
    public function buyer() { return $this->belongsTo(Customer::class, 'buyer_customer_id'); }
}
