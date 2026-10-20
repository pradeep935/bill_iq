<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessAccountSetting extends Model
{
    protected static function booted(): void
    {
        static::created(function (self $settings) {
            \Database\Seeders\PettyCashAccountSeeder::seedForBusiness((int) $settings->business_id);
            \Database\Seeders\ExpenseIncomeCategorySeeder::seedForBusiness((int) $settings->business_id);
        });
    }

    protected $table = 'business_account_settings';
    protected $guarded = [];
}
