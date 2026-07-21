<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetCategory extends Model
{
    use SoftDeletes;
    protected $table = 'asset_categories';
    protected $guarded = [];
    protected $casts = ['default_depreciation_rate' => 'decimal:4', 'default_residual_value_percent' => 'decimal:4', 'capitalisation_threshold' => 'decimal:2', 'is_system' => 'boolean'];
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function assetAccount() { return $this->belongsTo(Account::class, 'asset_account_id'); }
    public function accumulatedDepreciationAccount() { return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id'); }
    public function depreciationExpenseAccount() { return $this->belongsTo(Account::class, 'depreciation_expense_account_id'); }
    public function assets() { return $this->hasMany(FixedAsset::class); }
}
