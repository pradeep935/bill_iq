<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opening_stock_items') && Schema::hasColumn('opening_stock_items', 'opening_stock_entry_id')) {
            DB::statement('ALTER TABLE opening_stock_items MODIFY opening_stock_entry_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('opening_stock_items') && Schema::hasColumn('opening_stock_items', 'opening_stock_entry_id')) {
            DB::statement('ALTER TABLE opening_stock_items MODIFY opening_stock_entry_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
