<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('accounts') || !Schema::hasColumn('accounts', 'account_type')) {
            return;
        }

        DB::statement('ALTER TABLE accounts MODIFY account_type VARCHAR(50) NULL');
    }

    public function down(): void
    {
        // Preserve widened account_type compatibility.
    }
};
