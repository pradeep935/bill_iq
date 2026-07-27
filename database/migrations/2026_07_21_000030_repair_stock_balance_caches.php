<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'current_stock')) {
            Schema::table('products', function (Blueprint $table) {
                $table->decimal('current_stock', 15, 3)->default(0)->after('maximum_stock');
            });
        }

        if (!Schema::hasTable('warehouse_product_stocks')) {
            Schema::create('warehouse_product_stocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->unsignedBigInteger('warehouse_location_id')->nullable();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->decimal('quantity_on_hand', 15, 3)->default(0);
                $table->decimal('reserved_quantity', 15, 3)->default(0);
                $table->decimal('available_quantity', 15, 3)->default(0);
                $table->decimal('average_cost', 15, 2)->default(0);
                $table->decimal('stock_value', 15, 2)->default(0);
                $table->timestamps();

                $table->index(['business_id', 'branch_id', 'warehouse_id', 'product_id'], 'warehouse_stock_scope_index');
            });
        }

        $this->backfillBalances();
    }

    public function down(): void
    {
        if (Schema::hasTable('warehouse_product_stocks')) {
            Schema::dropIfExists('warehouse_product_stocks');
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'current_stock')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('current_stock');
            });
        }
    }

    private function backfillBalances(): void
    {
        if (!Schema::hasTable('stock_ledgers')) {
            return;
        }

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'current_stock')) {
            $rows = DB::table('stock_ledgers')
                ->selectRaw('business_id, product_id, COALESCE(SUM(quantity_in), 0) - COALESCE(SUM(quantity_out), 0) as quantity')
                ->groupBy('business_id', 'product_id')
                ->get();

            foreach ($rows as $row) {
                DB::table('products')
                    ->where('id', $row->product_id)
                    ->update(['current_stock' => $row->quantity]);
            }
        }

        if (Schema::hasTable('product_variant_items') && Schema::hasColumn('product_variant_items', 'current_stock')) {
            $variantRows = DB::table('stock_ledgers')
                ->whereNotNull('product_variant_id')
                ->selectRaw('business_id, product_id, product_variant_id, COALESCE(SUM(quantity_in), 0) - COALESCE(SUM(quantity_out), 0) as quantity')
                ->groupBy('business_id', 'product_id', 'product_variant_id')
                ->get();

            foreach ($variantRows as $row) {
                DB::table('product_variant_items')
                    ->where('id', $row->product_variant_id)
                    ->where('product_id', $row->product_id)
                    ->update(['current_stock' => $row->quantity]);
            }
        }

        if (!Schema::hasTable('warehouse_product_stocks')) {
            return;
        }

        $balanceRows = DB::table('stock_ledgers')
            ->selectRaw('
                business_id,
                branch_id,
                warehouse_id,
                product_id,
                product_variant_id,
                batch_id,
                COALESCE(SUM(quantity_in), 0) - COALESCE(SUM(quantity_out), 0) as quantity,
                CASE
                    WHEN COALESCE(SUM(CASE WHEN quantity_in > 0 THEN quantity_in ELSE 0 END), 0) = 0
                    THEN 0
                    ELSE COALESCE(SUM(CASE WHEN quantity_in > 0 THEN quantity_in * unit_cost ELSE 0 END), 0)
                        / COALESCE(SUM(CASE WHEN quantity_in > 0 THEN quantity_in ELSE 0 END), 1)
                END as average_cost
            ')
            ->groupBy('business_id', 'branch_id', 'warehouse_id', 'product_id', 'product_variant_id', 'batch_id')
            ->get();

        foreach ($balanceRows as $row) {
            DB::table('warehouse_product_stocks')->insert([
                'business_id' => $row->business_id,
                'branch_id' => $row->branch_id,
                'warehouse_id' => $row->warehouse_id,
                'warehouse_location_id' => null,
                'product_id' => $row->product_id,
                'product_variant_id' => $row->product_variant_id,
                'batch_id' => $row->batch_id,
                'quantity_on_hand' => $row->quantity,
                'reserved_quantity' => 0,
                'available_quantity' => $row->quantity,
                'average_cost' => $row->average_cost,
                'stock_value' => round((float) $row->quantity * (float) $row->average_cost, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
