<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    protected $table = 'accounts';
    protected $guarded = [];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_system' => 'boolean',
        'is_reconciliation_enabled' => 'boolean',
    ];

    public function scopeCategoryPosting($query, int $businessId, string $type)
    {
        $settings = BusinessAccountSetting::query()->where('business_id', $businessId)->first();
        $roles = ['sales_account_id', 'purchase_account_id', 'sales_return_account_id', 'purchase_return_account_id', 'cost_of_goods_sold_account_id', 'round_off_account_id'];
        $excluded = collect($roles)->map(fn ($role) => $settings?->{$role})->filter()->values()->all();
        return $query->where('business_id', $businessId)->where('account_type', $type)
            ->where('status', 'active')->where('is_control_account', false)
            ->whereNotIn('id', $excluded)
            ->whereNotIn('account_code', ['SALES', 'PURCHASE', 'SALES-RET', 'PUR-RET', 'COGS', 'ROUND']);
    }

    public function group()
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    public function entries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function expenseCategories()
    {
        return $this->hasMany(ExpenseCategory::class, 'expense_account_id');
    }

    public function incomeCategories()
    {
        return $this->hasMany(IncomeCategory::class, 'income_account_id');
    }

    public function maskAccountNumber(): ?string
    {
        if (!$this->account_number) return null;
        $last = substr($this->account_number, -4);
        return str_pad($last, strlen($this->account_number), 'X', STR_PAD_LEFT);
    }
}
