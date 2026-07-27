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

        if (!Schema::hasColumn('products', 'company_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedBigInteger('company_id')->nullable()->after('business_id')->index();
            });
        }

        if (Schema::hasColumn('products', 'business_id')) {
            DB::table('products')
                ->whereNull('company_id')
                ->update(['company_id' => DB::raw('business_id')]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'company_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
