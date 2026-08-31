<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hsn_masters', function (Blueprint $table) {
            if (!Schema::hasColumn('hsn_masters', 'reference_hsn_master_id')) {
                $table->unsignedBigInteger('reference_hsn_master_id')->nullable()->after('business_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hsn_masters', function (Blueprint $table) {
            if (Schema::hasColumn('hsn_masters', 'reference_hsn_master_id')) {
                $table->dropColumn('reference_hsn_master_id');
            }
        });
    }
};
