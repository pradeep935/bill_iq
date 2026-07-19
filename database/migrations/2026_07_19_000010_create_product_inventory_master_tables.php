<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hsn_code', 'effective_from']);
        });

        Schema::create('hsn_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hsn_id')->constrained('hsn_masters')->restrictOnDelete();
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

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->unique(['business_id', 'name', 'parent_id']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
            $table->string('product_type', 20)->default('goods')->after('business_id');
            $table->string('short_name')->nullable()->after('name');
            $table->text('description')->nullable()->after('sku');
            $table->foreignId('category_id')->nullable()->after('description')->constrained('product_categories')->nullOnDelete();
            $table->foreignId('sub_category_id')->nullable()->after('category_id')->constrained('product_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->after('sub_category_id')->constrained('brands')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->after('brand_id')->constrained('units')->nullOnDelete();
            $table->foreignId('secondary_unit_id')->nullable()->after('unit_id')->constrained('units')->nullOnDelete();
            $table->decimal('conversion_factor', 12, 4)->nullable()->after('secondary_unit_id');
            $table->foreignId('hsn_id')->nullable()->after('hsn')->constrained('hsn_masters')->nullOnDelete();
            $table->boolean('tax_inclusive')->default(false)->after('gst_rate');
            $table->boolean('track_inventory')->default(true)->after('tax_inclusive');
            $table->string('tracking_type', 20)->default('none')->after('track_inventory');
            $table->boolean('has_expiry')->default(false)->after('tracking_type');
            $table->boolean('allow_negative_stock')->default(false)->after('has_expiry');
            $table->decimal('minimum_stock', 12, 3)->default(0)->after('reorder_level');
            $table->decimal('maximum_stock', 12, 3)->default(0)->after('minimum_stock');
            $table->decimal('safety_stock', 12, 3)->default(0)->after('maximum_stock');
            $table->foreignId('purchase_account_id')->nullable()->after('safety_stock')->constrained('accounts')->nullOnDelete();
            $table->foreignId('sales_account_id')->nullable()->after('purchase_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('inventory_account_id')->nullable()->after('sales_account_id')->constrained('accounts')->nullOnDelete();
            $table->decimal('default_purchase_price', 12, 2)->default(0)->after('inventory_account_id');
            $table->decimal('default_selling_price', 12, 2)->default(0)->after('default_purchase_price');
            $table->decimal('mrp', 12, 2)->nullable()->after('default_selling_price');
            $table->string('status', 20)->default('active')->after('mrp');
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'sku']);
        });

        Schema::create('category_hsn_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->foreignId('hsn_id')->constrained('hsn_masters')->restrictOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('barcode')->index();
            $table->string('type', 30)->default('internal');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->unique(['business_id', 'barcode']);
        });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('batch_number');
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('mrp', 12, 2)->nullable();
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->unique(['business_id', 'product_id', 'batch_number']);
        });

        Schema::create('product_serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->string('serial_number');
            $table->string('status', 30)->default('available')->index();
            $table->timestamps();

            $table->unique(['business_id', 'serial_number']);
        });

        Schema::create('stock_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->foreignId('serial_number_id')->nullable()->constrained('product_serial_numbers')->nullOnDelete();
            $table->string('transaction_type', 40)->index();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->decimal('quantity_in', 12, 3)->default(0);
            $table->decimal('quantity_out', 12, 3)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('stock_value', 12, 2)->default(0);
            $table->timestamp('transaction_date')->useCurrent();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('opening_stock_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->date('entry_date');
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('opening_stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_stock_entry_id')->constrained('opening_stock_entries')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->unsignedBigInteger('warehouse_location_id')->nullable();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('stock_value', 12, 2)->default(0);
            $table->foreignId('serial_number_id')->nullable()->constrained('product_serial_numbers')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('product_purchase_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('purchase_item_id')->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('landed_cost', 12, 2)->default(0);
            $table->decimal('quantity', 12, 3)->default(0);
            $table->date('purchase_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_purchase_prices');
        Schema::dropIfExists('opening_stock_items');
        Schema::dropIfExists('opening_stock_entries');
        Schema::dropIfExists('stock_ledgers');
        Schema::dropIfExists('product_serial_numbers');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('category_hsn_mappings');
        Schema::dropIfExists('units');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('hsn_tax_rates');
        Schema::dropIfExists('hsn_masters');
    }
};
