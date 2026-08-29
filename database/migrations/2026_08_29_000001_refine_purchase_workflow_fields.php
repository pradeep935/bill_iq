<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_orders', 'payment_terms')) {
                    $table->string('payment_terms')->nullable()->after('expected_delivery_date');
                }
                if (!Schema::hasColumn('purchase_orders', 'supplier_reference')) {
                    $table->string('supplier_reference')->nullable()->after('payment_terms');
                }
                if (!Schema::hasColumn('purchase_orders', 'currency')) {
                    $table->string('currency', 10)->default('INR')->after('supplier_reference');
                }
            });
        }

        if (Schema::hasTable('goods_receipts')) {
            Schema::table('goods_receipts', function (Blueprint $table) {
                if (!Schema::hasColumn('goods_receipts', 'supplier_invoice_number')) {
                    $table->string('supplier_invoice_number')->nullable()->after('supplier_challan_number');
                }
                if (!Schema::hasColumn('goods_receipts', 'vehicle_number')) {
                    $table->string('vehicle_number')->nullable()->after('supplier_invoice_number');
                }
            });
        }

        if (Schema::hasTable('goods_receipt_items')) {
            Schema::table('goods_receipt_items', function (Blueprint $table) {
                if (!Schema::hasColumn('goods_receipt_items', 'batch_number')) {
                    $table->string('batch_number')->nullable()->after('unit_cost');
                }
                if (!Schema::hasColumn('goods_receipt_items', 'manufacturing_date')) {
                    $table->date('manufacturing_date')->nullable()->after('batch_number');
                }
                if (!Schema::hasColumn('goods_receipt_items', 'expiry_date')) {
                    $table->date('expiry_date')->nullable()->after('manufacturing_date');
                }
                if (!Schema::hasColumn('goods_receipt_items', 'remarks')) {
                    $table->text('remarks')->nullable()->after('warehouse_location');
                }
            });
        }
    }

    public function down(): void
    {
        // Data-preserving migration: refined workflow columns are intentionally retained.
    }
};
