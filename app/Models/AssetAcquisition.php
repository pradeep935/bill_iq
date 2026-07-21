<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetAcquisition extends Model
{
    protected $table = 'asset_acquisitions';
    protected $guarded = [];
    protected $casts = ['acquisition_date' => 'date', 'invoice_date' => 'date', 'base_cost' => 'decimal:2', 'additional_cost' => 'decimal:2', 'tax_amount' => 'decimal:2', 'input_tax_credit_eligible' => 'boolean', 'non_creditable_tax_amount' => 'decimal:2', 'total_capitalizable_cost' => 'decimal:2'];
    public function branch() { return $this->belongsTo(Branch::class); }
    public function category() { return $this->belongsTo(AssetCategory::class, 'asset_category_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
}
