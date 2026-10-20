<?php

use Database\Seeders\ExpenseIncomeCategorySeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new ExpenseIncomeCategorySeeder)->run();
    }

    public function down(): void
    {
        // Preserve categories, which may already be referenced by vouchers.
    }
};
