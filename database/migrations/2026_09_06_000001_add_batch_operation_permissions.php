<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        foreach (['batch.adjust', 'batch.reclassify', 'batch.writeoff'] as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['module' => 'inventory', 'description' => ucwords(str_replace('.', ' ', $name)), 'created_at' => now(), 'updated_at' => now()]);
        }
        // Existing role grants remain unchanged; administrators can grant these operations explicitly.
    }

    public function down(): void {}
};
