<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_serial_numbers') || Schema::hasColumn('product_serial_numbers', 'deleted_at')) {
            return;
        }

        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_serial_numbers') || !Schema::hasColumn('product_serial_numbers', 'deleted_at')) {
            return;
        }

        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
