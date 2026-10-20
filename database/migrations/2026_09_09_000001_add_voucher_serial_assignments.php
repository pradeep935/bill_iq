<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['opening_stock_items', 'sales_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->json('serials')->nullable());
        }
        Schema::table('serial_number_histories', fn (Blueprint $table) => $table->dateTime('occurred_at')->nullable());
    }

    public function down(): void
    {
        foreach (['opening_stock_items', 'sales_items'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('serials'));
        }
        Schema::table('serial_number_histories', fn (Blueprint $table) => $table->dropColumn('occurred_at'));
    }
};
