<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'type')) {
                $table->string('type', 50)->nullable()->after('name');
            }

            if (!Schema::hasColumn('branches', 'address')) {
                $table->text('address')->nullable()->after('city');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'address')) {
                $table->dropColumn('address');
            }

            if (Schema::hasColumn('branches', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
