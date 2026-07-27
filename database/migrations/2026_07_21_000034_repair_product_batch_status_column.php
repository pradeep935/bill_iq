<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_batches') || !Schema::hasColumn('product_batches', 'status')) {
            return;
        }

        DB::statement("ALTER TABLE product_batches MODIFY status VARCHAR(30) NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_batches') || !Schema::hasColumn('product_batches', 'status')) {
            return;
        }

        DB::statement("ALTER TABLE product_batches MODIFY status ENUM('active','blocked','expired') NOT NULL DEFAULT 'active'");
    }
};
