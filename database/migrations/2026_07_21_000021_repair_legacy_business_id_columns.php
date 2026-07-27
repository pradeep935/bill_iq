<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['branches', 'suppliers', 'customers', 'accounts', 'products'] as $tableName) {
            if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'business_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('business_id')->nullable()->index();
            });

            if (Schema::hasColumn($tableName, 'tenant_id')) {
                DB::table($tableName)->update(['business_id' => DB::raw('COALESCE(tenant_id, 1)')]);
            }
        }
    }

    public function down(): void
    {
        // Preserve compatibility columns/data.
    }
};
