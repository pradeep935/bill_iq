<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'minimum_stock')) {
                $table->decimal('minimum_stock', 15, 3)->default(0)->after('min_stock');
            }
        });

        if (Schema::hasColumn('products', 'min_stock')) {
            DB::table('products')
                ->where(function ($query) {
                    $query->whereNull('minimum_stock')->orWhere('minimum_stock', 0);
                })
                ->update(['minimum_stock' => DB::raw('COALESCE(min_stock, 0)')]);
        }
    }

    public function down(): void
    {
        // Preserve compatibility column/data.
    }
};
