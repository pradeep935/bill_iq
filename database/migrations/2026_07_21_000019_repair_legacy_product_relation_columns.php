<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'description')) {
                $table->text('description')->nullable();
            }

            if (!Schema::hasColumn('products', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable();
            }

            if (!Schema::hasColumn('products', 'sub_category_id')) {
                $table->unsignedBigInteger('sub_category_id')->nullable();
            }

            if (!Schema::hasColumn('products', 'unit_id')) {
                $table->unsignedBigInteger('unit_id')->nullable();
            }

            if (!Schema::hasColumn('products', 'hsn_id')) {
                $table->unsignedBigInteger('hsn_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Preserve compatibility columns/data.
    }
};
