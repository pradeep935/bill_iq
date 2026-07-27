<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'status')) {
            return;
        }

        DB::statement("ALTER TABLE products MODIFY status VARCHAR(30) NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'status')) {
            return;
        }

        DB::table('products')
            ->where('status', 'discontinued')
            ->update(['status' => 'inactive']);

        DB::statement("ALTER TABLE products MODIFY status ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");
    }
};
