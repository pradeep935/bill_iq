<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\BusinessAccountSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PettyCashAccountSeeder extends Seeder
{
    public function run(): void
    {
        foreach (BusinessAccountSetting::query()->pluck('business_id') as $businessId) self::seedForBusiness((int) $businessId);
    }

    public static function seedForBusiness(int $businessId): void
    {
        DB::transaction(function () use ($businessId) {
            $settings = BusinessAccountSetting::query()->where('business_id', $businessId)->lockForUpdate()->first();
            if (!$settings || $settings->petty_cash_advance_account_id || $settings->supplier_advance_account_id) return;
            // PETTY-ADV is already defined by the expense accounting foundation migration.
            // Lazy company setup has no account groups: asset type supplies its debit nature.
            $account = Account::withTrashed()->firstOrCreate(
                ['business_id' => $businessId, 'account_code' => 'PETTY-ADV'],
                ['account_name' => 'Employee Petty Cash Advance', 'name' => 'Employee Petty Cash Advance',
                    'account_type' => 'asset', 'opening_balance' => 0, 'opening_balance_type' => 'debit',
                    'current_balance' => 0, 'is_system' => true, 'status' => 'active']
            );
            $debitNature = $account->group?->nature ?? ($account->account_type === 'asset' ? 'debit' : null);
            if ($account->trashed() || $account->status !== 'active' || !in_array($account->account_type, ['asset', 'ledger'], true) || $debitNature !== 'debit') return;
            $settings->update(['petty_cash_advance_account_id' => $account->id]);
        });
    }
}
