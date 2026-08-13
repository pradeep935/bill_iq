<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('warehouse_product_stocks')) {
            return;
        }

        Schema::table('warehouse_product_stocks', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouse_product_stocks', 'product_variant_id')) {
                $table->foreignId('product_variant_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_variant_items')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('warehouse_product_stocks', 'batch_id')) {
                $table->foreignId('batch_id')
                    ->nullable()
                    ->after('product_variant_id')
                    ->constrained('product_batches')
                    ->nullOnDelete();
            }
        });

        $this->addScopeIndex();
    }

    public function down(): void
    {
        if (!Schema::hasTable('warehouse_product_stocks')) {
            return;
        }

        $this->dropIndexIfExists('warehouse_product_stocks', 'warehouse_stock_variant_batch_scope_index');

        Schema::table('warehouse_product_stocks', function (Blueprint $table) {
            if (Schema::hasColumn('warehouse_product_stocks', 'batch_id')) {
                $table->dropConstrainedForeignId('batch_id');
            }

            if (Schema::hasColumn('warehouse_product_stocks', 'product_variant_id')) {
                $table->dropConstrainedForeignId('product_variant_id');
            }
        });
    }

    private function addScopeIndex(): void
    {
        $exists = collect(DB::select('SHOW INDEX FROM warehouse_product_stocks'))
            ->contains(fn ($index) => $index->Key_name === 'warehouse_stock_variant_batch_scope_index');

        if ($exists) {
            return;
        }

        Schema::table('warehouse_product_stocks', function (Blueprint $table) {
            $table->index(
                ['business_id', 'branch_id', 'warehouse_id', 'warehouse_location_id', 'product_id', 'product_variant_id', 'batch_id'],
                'warehouse_stock_variant_batch_scope_index'
            );
        });
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        $exists = collect(DB::select("SHOW INDEX FROM {$table}"))
            ->contains(fn ($index) => $index->Key_name === $indexName);

        if (!$exists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }
};
