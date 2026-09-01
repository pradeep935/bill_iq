<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['stock_ledgers', 'stock_adjustment_items', 'stock_count_items'] as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'warehouse_location_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('warehouse_location_id')->nullable()->index()->after('warehouse_location');
                });
            }
        }

        if (Schema::hasTable('goods_receipt_items') && !Schema::hasColumn('goods_receipt_items', 'warehouse_location_id')) {
            Schema::table('goods_receipt_items', function (Blueprint $table) {
                $table->unsignedBigInteger('warehouse_location_id')->nullable()->index()->after('warehouse_location');
            });
        }

        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_transfer_items', 'source_location_id')) {
                    $table->unsignedBigInteger('source_location_id')->nullable()->index()->after('source_location');
                }
                if (!Schema::hasColumn('stock_transfer_items', 'destination_location_id')) {
                    $table->unsignedBigInteger('destination_location_id')->nullable()->index()->after('destination_location');
                }
            });
        }

        if (Schema::hasTable('location_transfer_items')) {
            Schema::table('location_transfer_items', function (Blueprint $table) {
                if (!Schema::hasColumn('location_transfer_items', 'from_location_id')) {
                    $table->unsignedBigInteger('from_location_id')->nullable()->index()->after('from_location');
                }
                if (!Schema::hasColumn('location_transfer_items', 'to_location_id')) {
                    $table->unsignedBigInteger('to_location_id')->nullable()->index()->after('to_location');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('location_transfer_items')) {
            Schema::table('location_transfer_items', function (Blueprint $table) {
                foreach (['from_location_id', 'to_location_id'] as $column) {
                    if (Schema::hasColumn('location_transfer_items', $column)) $table->dropColumn($column);
                }
            });
        }

        if (Schema::hasTable('stock_transfer_items')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                foreach (['source_location_id', 'destination_location_id'] as $column) {
                    if (Schema::hasColumn('stock_transfer_items', $column)) $table->dropColumn($column);
                }
            });
        }

        foreach (['stock_count_items', 'stock_adjustment_items', 'stock_ledgers'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'warehouse_location_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('warehouse_location_id'));
            }
        }

        if (Schema::hasTable('goods_receipt_items') && Schema::hasColumn('goods_receipt_items', 'warehouse_location_id')) {
            Schema::table('goods_receipt_items', fn (Blueprint $table) => $table->dropColumn('warehouse_location_id'));
        }
    }
};
