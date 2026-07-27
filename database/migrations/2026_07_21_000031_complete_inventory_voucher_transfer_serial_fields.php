<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_transfer_items')) {
            return;
        }

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_transfer_items', 'source_serial_id')) {
                $table->unsignedBigInteger('source_serial_id')->nullable()->after('destination_batch_id')->index();
            }

            if (!Schema::hasColumn('stock_transfer_items', 'destination_serial_id')) {
                $table->unsignedBigInteger('destination_serial_id')->nullable()->after('source_serial_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('stock_transfer_items')) {
            return;
        }

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            if (Schema::hasColumn('stock_transfer_items', 'destination_serial_id')) {
                $table->dropColumn('destination_serial_id');
            }

            if (Schema::hasColumn('stock_transfer_items', 'source_serial_id')) {
                $table->dropColumn('source_serial_id');
            }
        });
    }
};
