<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || Schema::hasColumn('products', 'maximum_stock')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('maximum_stock', 15, 3)->default(0);
        });
    }

    public function down(): void
    {
        // Preserve compatibility column/data.
    }
};
