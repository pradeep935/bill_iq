<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_ledgers')) {
            Schema::table('stock_ledgers', function (Blueprint $table) {
                if (!Schema::hasColumn('stock_ledgers', 'product_variant_id')) {
                    $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variant_items')->nullOnDelete();
                }

                if (!Schema::hasColumn('stock_ledgers', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('remarks')->constrained('users')->nullOnDelete();
                }

                if (!Schema::hasColumn('stock_ledgers', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }

                $table->index(['business_id', 'branch_id', 'warehouse_id', 'product_id'], 'stock_ledgers_scope_index');
                $table->index(['business_id', 'transaction_type', 'transaction_date'], 'stock_ledgers_type_date_index');
                $table->index(['reference_type', 'reference_id'], 'stock_ledgers_reference_index');
            });
        }

        if (!Schema::hasTable('opening_stock_vouchers')) {
            Schema::create('opening_stock_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('voucher_number', 40);
                $table->date('opening_date');
                $table->text('remarks')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'voucher_number']);
                $table->index(['business_id', 'branch_id', 'warehouse_id']);
                $table->index(['business_id', 'status', 'opening_date']);
            });
        }

        if (Schema::hasTable('opening_stock_items')) {
            Schema::table('opening_stock_items', function (Blueprint $table) {
                if (!Schema::hasColumn('opening_stock_items', 'opening_stock_voucher_id')) {
                    $table->foreignId('opening_stock_voucher_id')->nullable()->after('id')->constrained('opening_stock_vouchers')->cascadeOnDelete();
                }

                if (!Schema::hasColumn('opening_stock_items', 'product_variant_id')) {
                    $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variant_items')->nullOnDelete();
                }

                if (!Schema::hasColumn('opening_stock_items', 'batch_no')) {
                    $table->string('batch_no', 100)->nullable()->after('batch_id');
                }

                if (!Schema::hasColumn('opening_stock_items', 'purchase_cost')) {
                    $table->decimal('purchase_cost', 15, 2)->default(0)->after('quantity');
                }

                if (!Schema::hasColumn('opening_stock_items', 'selling_price')) {
                    $table->decimal('selling_price', 15, 2)->default(0)->after('purchase_cost');
                }

                if (!Schema::hasColumn('opening_stock_items', 'mrp')) {
                    $table->decimal('mrp', 15, 2)->nullable()->after('selling_price');
                }

                if (!Schema::hasColumn('opening_stock_items', 'warehouse_location')) {
                    $table->string('warehouse_location')->nullable()->after('mrp');
                }

                if (!Schema::hasColumn('opening_stock_items', 'manufacturing_date')) {
                    $table->date('manufacturing_date')->nullable()->after('warehouse_location');
                }

                if (!Schema::hasColumn('opening_stock_items', 'expiry_date')) {
                    $table->date('expiry_date')->nullable()->after('manufacturing_date');
                }

                if (!Schema::hasColumn('opening_stock_items', 'remarks')) {
                    $table->text('remarks')->nullable()->after('expiry_date');
                }

                $table->index(['opening_stock_voucher_id', 'product_id'], 'opening_stock_items_voucher_product_index');
                $table->index(['product_id', 'batch_id'], 'opening_stock_items_product_batch_index');
            });
        }
    }

    public function down(): void
    {
        // Data-preserving migration: ledger and opening stock history are intentionally retained.
    }
};
