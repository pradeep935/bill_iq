<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['accounts', 'branches', 'customers', 'suppliers', 'products'] as $tableName) {
            if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            DB::statement("ALTER TABLE {$tableName} MODIFY tenant_id BIGINT UNSIGNED NULL");
        }
    }

    public function down(): void
    {
        // Preserve relaxed legacy tenant compatibility.
    }
};
