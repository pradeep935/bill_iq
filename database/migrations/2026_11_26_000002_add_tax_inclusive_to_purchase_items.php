<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('purchase_items') && !Schema::hasColumn('purchase_items', 'tax_inclusive')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->boolean('tax_inclusive')->default(false)->after('purchase_rate');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_items', 'tax_inclusive')) {
            Schema::table('purchase_items', fn (Blueprint $table) => $table->dropColumn('tax_inclusive'));
        }
    }
};
