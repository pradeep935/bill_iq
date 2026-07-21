<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'category')) {
                $table->string('category', 150)->nullable()->after('description');
            }

            if (!Schema::hasColumn('products', 'brand')) {
                $table->string('brand', 150)->nullable()->after('category');
            }

            if (!Schema::hasColumn('products', 'variant')) {
                $table->string('variant', 150)->nullable()->after('brand');
            }

            if (!Schema::hasColumn('products', 'unit')) {
                $table->string('unit', 30)->default('PCS')->after('variant');
            }

            if (!Schema::hasColumn('products', 'hsn_master_id')) {
                $table->foreignId('hsn_master_id')->nullable()->after('unit')->constrained('hsn_masters')->nullOnDelete();
            }

            if (!Schema::hasColumn('products', 'hsn_code')) {
                $table->string('hsn_code', 20)->nullable()->after('hsn_master_id');
            }

            if (!Schema::hasColumn('products', 'taxability')) {
                $table->string('taxability', 30)->default('taxable')->after('hsn_code');
            }

            if (!Schema::hasColumn('products', 'cess_rate')) {
                $table->decimal('cess_rate', 5, 2)->default(0)->after('gst_rate');
            }

            if (!Schema::hasColumn('products', 'reverse_charge')) {
                $table->string('reverse_charge', 5)->default('no')->after('cess_rate');
            }

            if (!Schema::hasColumn('products', 'invoice_description')) {
                $table->string('invoice_description', 500)->nullable()->after('reverse_charge');
            }

            if (!Schema::hasColumn('products', 'selling_price')) {
                $table->decimal('selling_price', 15, 2)->default(0)->after('invoice_description');
            }

            if (!Schema::hasColumn('products', 'cost_price')) {
                $table->decimal('cost_price', 15, 2)->default(0)->after('selling_price');
            }

            if (!Schema::hasColumn('products', 'reorder_stock')) {
                $table->decimal('reorder_stock', 15, 3)->default(0)->after('minimum_stock');
            }

            if (!Schema::hasColumn('products', 'primary_barcode')) {
                $table->string('primary_barcode', 100)->nullable()->after('sku');
            }

            if (!Schema::hasColumn('products', 'extra_barcodes')) {
                $table->text('extra_barcodes')->nullable()->after('primary_barcode');
            }

            if (!Schema::hasColumn('products', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        // Existing product data is preserved intentionally.
    }
};
