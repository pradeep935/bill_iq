<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppliers')) {
            Schema::table('suppliers', function (Blueprint $table) {
                if (!Schema::hasColumn('suppliers', 'supplier_code')) {
                    $table->string('supplier_code', 40)->nullable()->after('business_id');
                }

                if (!Schema::hasColumn('suppliers', 'supplier_name')) {
                    $table->string('supplier_name')->nullable()->after('supplier_code');
                }

                if (!Schema::hasColumn('suppliers', 'contact_person')) {
                    $table->string('contact_person')->nullable()->after('supplier_name');
                }

                if (!Schema::hasColumn('suppliers', 'mobile')) {
                    $table->string('mobile', 20)->nullable()->after('contact_person');
                }

                if (!Schema::hasColumn('suppliers', 'gstin')) {
                    $table->string('gstin', 15)->nullable()->after('email');
                }

                if (!Schema::hasColumn('suppliers', 'pan')) {
                    $table->string('pan', 10)->nullable()->after('gstin');
                }

                if (!Schema::hasColumn('suppliers', 'billing_address')) {
                    $table->text('billing_address')->nullable()->after('pan');
                }

                if (!Schema::hasColumn('suppliers', 'shipping_address')) {
                    $table->text('shipping_address')->nullable()->after('billing_address');
                }

                if (!Schema::hasColumn('suppliers', 'state_id')) {
                    $table->unsignedBigInteger('state_id')->nullable()->after('shipping_address');
                }

                if (!Schema::hasColumn('suppliers', 'city')) {
                    $table->string('city', 120)->nullable()->after('state_id');
                }

                if (!Schema::hasColumn('suppliers', 'pincode')) {
                    $table->string('pincode', 12)->nullable()->after('city');
                }

                if (!Schema::hasColumn('suppliers', 'opening_balance')) {
                    $table->decimal('opening_balance', 15, 2)->default(0)->after('pincode');
                }

                if (!Schema::hasColumn('suppliers', 'opening_balance_type')) {
                    $table->string('opening_balance_type', 10)->default('credit')->after('opening_balance');
                }

                if (!Schema::hasColumn('suppliers', 'credit_limit')) {
                    $table->decimal('credit_limit', 15, 2)->nullable()->after('opening_balance_type');
                }

                if (!Schema::hasColumn('suppliers', 'credit_days')) {
                    $table->integer('credit_days')->nullable()->after('credit_limit');
                }

                if (!Schema::hasColumn('suppliers', 'created_by')) {
                    $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
                }

                if (!Schema::hasColumn('suppliers', 'deleted_at')) {
                    $table->softDeletes();
                }

                $table->index(['business_id', 'status'], 'suppliers_business_status_index');
                $table->index(['business_id', 'supplier_code'], 'suppliers_business_code_index');
                $table->index(['business_id', 'gstin'], 'suppliers_business_gstin_index');
            });
        }

        if (!Schema::hasTable('purchase_vouchers')) {
            Schema::create('purchase_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->string('voucher_number', 40);
                $table->string('supplier_invoice_number')->nullable();
                $table->date('purchase_date');
                $table->date('supplier_invoice_date')->nullable();
                $table->date('due_date')->nullable();
                $table->string('purchase_type', 20)->default('credit');
                $table->string('tax_type', 20)->default('intrastate');
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->string('discount_type', 20)->nullable();
                $table->decimal('discount_value', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->decimal('cgst_amount', 15, 2)->default(0);
                $table->decimal('sgst_amount', 15, 2)->default(0);
                $table->decimal('igst_amount', 15, 2)->default(0);
                $table->decimal('cess_amount', 15, 2)->default(0);
                $table->decimal('round_off', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->decimal('paid_amount', 15, 2)->default(0);
                $table->decimal('balance_amount', 15, 2)->default(0);
                $table->string('payment_status', 20)->default('unpaid')->index();
                $table->string('status', 20)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'voucher_number']);
                $table->index(['business_id', 'purchase_date']);
                $table->index(['business_id', 'supplier_id']);
            });
        }

        if (!Schema::hasTable('purchase_items')) {
            Schema::create('purchase_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_voucher_id')->constrained('purchase_vouchers')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->decimal('quantity', 15, 3);
                $table->decimal('free_quantity', 15, 3)->default(0);
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->decimal('purchase_rate', 15, 2)->default(0);
                $table->decimal('selling_price', 15, 2)->nullable();
                $table->decimal('mrp', 15, 2)->nullable();
                $table->string('discount_type', 20)->nullable();
                $table->decimal('discount_value', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->decimal('gst_rate', 5, 2)->default(0);
                $table->decimal('cgst_rate', 5, 2)->default(0);
                $table->decimal('sgst_rate', 5, 2)->default(0);
                $table->decimal('igst_rate', 5, 2)->default(0);
                $table->decimal('cgst_amount', 15, 2)->default(0);
                $table->decimal('sgst_amount', 15, 2)->default(0);
                $table->decimal('igst_amount', 15, 2)->default(0);
                $table->decimal('cess_rate', 5, 2)->default(0);
                $table->decimal('cess_amount', 15, 2)->default(0);
                $table->decimal('line_total', 15, 2)->default(0);
                $table->string('batch_number')->nullable();
                $table->date('manufacturing_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->string('warehouse_location')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();

                $table->index(['purchase_voucher_id', 'product_id']);
                $table->index(['product_id', 'batch_id']);
            });
        }
    }

    public function down(): void
    {
        // Data-preserving migration: purchase and supplier history are intentionally retained.
    }
};
