<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'state_id')) {
                $table->unsignedBigInteger('state_id')->nullable()->after('state')->index();
            }

            if (!Schema::hasColumn('branches', 'city_id')) {
                $table->unsignedBigInteger('city_id')->nullable()->after('state_id')->index();
            }

            if (!Schema::hasColumn('branches', 'city')) {
                $table->string('city', 120)->nullable()->after('city_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'city_id')) {
                $table->dropColumn('city_id');
            }

            if (Schema::hasColumn('branches', 'state_id')) {
                $table->dropColumn('state_id');
            }

            if (Schema::hasColumn('branches', 'city')) {
                $table->dropColumn('city');
            }
        });
    }
};
