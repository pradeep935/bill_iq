<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Models\IncomeCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseIncomeCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (DB::table('companies')->pluck('id') as $businessId) {
            self::seedForBusiness((int) $businessId);
        }
    }

    public static function seedForBusiness(int $businessId): void
    {
        // Only these suggested ledgers are defined by Bill IQ's standard chart.
        DB::transaction(function () use ($businessId) {
            DB::table('companies')->where('id', $businessId)->lockForUpdate()->first();
            foreach ([['expense', 'SHIP-E', 'SHIP-EXP', 'Shipping Expense', ExpenseCategory::class], ['income', 'SHIP-I', 'SHIP-INC', 'Shipping Income', IncomeCategory::class]] as [$type, $ledgerCode, $code, $name, $model]) {
                $account = Account::query()->categoryPosting($businessId, $type)->where('account_code', $ledgerCode)->first();
                if (!$account) continue;
                // Include inactive and deleted categories: never recreate or overwrite a user's choice.
                if ($model::withTrashed()->where('business_id', $businessId)->where(function ($query) use ($code, $name, $type, $account) {
                    $query->where('category_code', $code)->orWhere('category_name', $name)->orWhere($type . '_account_id', $account->id);
                })->exists()) continue;
                $data = ['business_id' => $businessId, 'category_code' => $code, 'category_name' => $name, $type . '_account_id' => $account->id, 'status' => 'active', 'is_system' => true];
                if ($type === 'expense') $data += ['name' => $name, 'account_id' => $account->id];
                $model::create($data);
            }
        });
    }
}
