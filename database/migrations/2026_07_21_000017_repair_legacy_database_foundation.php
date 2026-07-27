<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('state')->nullable();
                $table->string('financial_year')->nullable();
                $table->timestamps();
            });

            DB::table('companies')->insert([
                'name' => 'ABC Retail Pvt Ltd',
                'state' => 'Noida',
                'financial_year' => '2026-27',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (!Schema::hasTable('warehouses')) {
            Schema::create('warehouses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('hsn_masters')) {
            Schema::create('hsn_masters', function (Blueprint $table) {
                $table->id();
                $table->string('hsn_code', 12)->index();
                $table->string('description');
                $table->string('chapter_code', 8)->nullable()->index();
                $table->decimal('gst_rate', 5, 2)->default(0);
                $table->decimal('cess_rate', 5, 2)->default(0);
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->string('source_reference')->nullable();
                $table->string('notification_number')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['hsn_code', 'effective_from']);
            });
        }

        if (!Schema::hasTable('hsn_tax_rates')) {
            Schema::create('hsn_tax_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hsn_id')->index();
                $table->decimal('gst_rate', 5, 2)->default(0);
                $table->decimal('cess_rate', 5, 2)->default(0);
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->string('notification_number')->nullable();
                $table->string('source_reference')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->index(['hsn_id', 'effective_from', 'effective_to']);
            });
        }

        if (!Schema::hasTable('product_categories')) {
            Schema::create('product_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->string('name');
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique(['business_id', 'name', 'parent_id']);
            });
        }

        if (!Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table) {
                $table->id();
                $table->string('code', 12)->unique();
                $table->string('name');
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });

            foreach (['PCS' => 'Pieces', 'NOS' => 'Numbers', 'BOX' => 'Box', 'PKT' => 'Packet', 'KG' => 'Kilogram', 'GM' => 'Gram', 'LTR' => 'Litre', 'ML' => 'Millilitre'] as $code => $name) {
                DB::table('units')->insert(['code' => $code, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (!Schema::hasTable('category_hsn_mappings')) {
            Schema::create('category_hsn_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('category_id')->index();
                $table->unsignedBigInteger('hsn_id')->index();
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_barcodes')) {
            Schema::create('product_barcodes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->string('barcode')->index();
                $table->string('type', 30)->default('internal');
                $table->string('barcode_type', 30)->nullable();
                $table->decimal('quantity', 12, 3)->default(1);
                $table->boolean('is_primary')->default(false);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique(['business_id', 'barcode']);
            });
        }

        if (!Schema::hasTable('product_serial_numbers')) {
            Schema::create('product_serial_numbers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('batch_id')->nullable()->index();
                $table->string('serial_number');
                $table->string('status', 30)->default('available')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('stock_ledgers')) {
            Schema::create('stock_ledgers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('batch_id')->nullable()->index();
                $table->unsignedBigInteger('serial_number_id')->nullable()->index();
                $table->string('transaction_type', 40)->index();
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->decimal('quantity_in', 15, 3)->default(0);
                $table->decimal('quantity_out', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('stock_value', 15, 2)->default(0);
                $table->timestamp('transaction_date')->useCurrent();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('opening_stock_entries')) {
            Schema::create('opening_stock_entries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->date('entry_date');
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('opening_stock_items')) {
            Schema::create('opening_stock_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('opening_stock_entry_id')->nullable()->index();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_location_id')->nullable();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('batch_id')->nullable()->index();
                $table->decimal('quantity', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('stock_value', 15, 2)->default(0);
                $table->unsignedBigInteger('serial_number_id')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_purchase_prices')) {
            Schema::create('product_purchase_prices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('supplier_id')->nullable()->index();
                $table->unsignedBigInteger('purchase_id')->nullable();
                $table->unsignedBigInteger('purchase_item_id')->nullable();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('landed_cost', 15, 2)->default(0);
                $table->decimal('quantity', 15, 3)->default(0);
                $table->date('purchase_date')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Repair migration only fills missing legacy gaps; data is intentionally preserved.
    }
};
