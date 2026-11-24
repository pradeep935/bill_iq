<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'is_sample')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_sample')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'is_sample')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_sample'));
        }
    }
};
