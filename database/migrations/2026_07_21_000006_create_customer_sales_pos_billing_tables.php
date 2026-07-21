<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->upgradeCustomers();
        $this->createSalesTables();
        $this->seedPaymentMethods();
        $this->seedPermissions();
    }

    private function upgradeCustomers(): void
    {
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('customer_code', 50);
                $table->string('customer_name');
                $table->string('customer_type', 30)->default('retail');
                $table->string('contact_person')->nullable();
                $table->string('mobile', 30)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('email')->nullable();
                $table->string('gstin', 20)->nullable();
                $table->string('pan', 20)->nullable();
                $table->text('billing_address')->nullable();
                $table->text('shipping_address')->nullable();
                $table->unsignedBigInteger('state_id')->nullable();
                $table->string('city')->nullable();
                $table->string('pincode', 20)->nullable();
                $table->decimal('opening_balance', 15, 2)->default(0);
                $table->string('opening_balance_type', 10)->nullable();
                $table->decimal('credit_limit', 15, 2)->nullable();
                $table->integer('credit_days')->nullable();
                $table->string('price_type', 30)->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'customer_code']);
                $table->index(['business_id', 'customer_name']);
                $table->index(['business_id', 'mobile']);
                $table->index(['business_id', 'gstin']);
            });

            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'business_id')) {
                $table->foreignId('business_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('customers', 'customer_code')) {
                $table->string('customer_code', 50)->nullable()->after('business_id');
            }
            if (!Schema::hasColumn('customers', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_code');
            }
            if (!Schema::hasColumn('customers', 'customer_type')) {
                $table->string('customer_type', 30)->default('retail')->after('customer_name');
            }
            if (!Schema::hasColumn('customers', 'contact_person')) {
                $table->string('contact_person')->nullable()->after('customer_type');
            }
            if (!Schema::hasColumn('customers', 'mobile')) {
                $table->string('mobile', 30)->nullable()->after('contact_person');
            }
            if (!Schema::hasColumn('customers', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('customers', 'pan')) {
                $table->string('pan', 20)->nullable()->after('gstin');
            }
            if (!Schema::hasColumn('customers', 'billing_address')) {
                $table->text('billing_address')->nullable()->after('pan');
            }
            if (!Schema::hasColumn('customers', 'shipping_address')) {
                $table->text('shipping_address')->nullable()->after('billing_address');
            }
            if (!Schema::hasColumn('customers', 'state_id')) {
                $table->unsignedBigInteger('state_id')->nullable()->after('shipping_address');
            }
            if (!Schema::hasColumn('customers', 'city')) {
                $table->string('city')->nullable()->after('state_id');
            }
            if (!Schema::hasColumn('customers', 'pincode')) {
                $table->string('pincode', 20)->nullable()->after('city');
            }
            if (!Schema::hasColumn('customers', 'opening_balance_type')) {
                $table->string('opening_balance_type', 10)->nullable()->after('opening_balance');
            }
            if (!Schema::hasColumn('customers', 'credit_limit')) {
                $table->decimal('credit_limit', 15, 2)->nullable()->after('opening_balance_type');
            }
            if (!Schema::hasColumn('customers', 'credit_days')) {
                $table->integer('credit_days')->nullable()->after('credit_limit');
            }
            if (!Schema::hasColumn('customers', 'price_type')) {
                $table->string('price_type', 30)->nullable()->after('credit_days');
            }
            if (!Schema::hasColumn('customers', 'status')) {
                $table->string('status', 20)->default('active')->index()->after('price_type');
            }
            if (!Schema::hasColumn('customers', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('customers', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('customers', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('customers')->whereNull('business_id')->update(['business_id' => DB::table('companies')->value('id') ?: 1]);
        DB::table('customers')->whereNull('customer_name')->update(['customer_name' => DB::raw('COALESCE(name, "Customer")')]);

        DB::table('customers')
            ->whereNull('customer_code')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($customer) {
                DB::table('customers')->where('id', $customer->id)->update([
                    'customer_code' => 'CUS-' . str_pad((string) $customer->id, 5, '0', STR_PAD_LEFT),
                ]);
            });
    }

    private function createSalesTables(): void
    {
        if (!Schema::hasTable('sales_vouchers')) {
            Schema::create('sales_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('voucher_number', 50);
                $table->string('invoice_number', 50);
                $table->date('invoice_date');
                $table->date('due_date')->nullable();
                $table->string('sale_type', 20)->default('cash');
                $table->string('invoice_type', 30)->default('tax_invoice');
                $table->string('tax_type', 20)->default('intrastate');
                $table->unsignedBigInteger('place_of_supply_state_id')->nullable();
                $table->string('customer_name_snapshot')->nullable();
                $table->string('customer_mobile_snapshot', 30)->nullable();
                $table->string('customer_gstin_snapshot', 20)->nullable();
                $table->text('billing_address_snapshot')->nullable();
                $table->text('shipping_address_snapshot')->nullable();
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('item_discount_amount', 15, 2)->default(0);
                $table->string('voucher_discount_type', 20)->nullable();
                $table->decimal('voucher_discount_value', 15, 2)->default(0);
                $table->decimal('voucher_discount_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->decimal('cgst_amount', 15, 2)->default(0);
                $table->decimal('sgst_amount', 15, 2)->default(0);
                $table->decimal('igst_amount', 15, 2)->default(0);
                $table->decimal('cess_amount', 15, 2)->default(0);
                $table->decimal('shipping_amount', 15, 2)->default(0);
                $table->decimal('other_charges', 15, 2)->default(0);
                $table->decimal('round_off', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->decimal('paid_amount', 15, 2)->default(0);
                $table->decimal('balance_amount', 15, 2)->default(0);
                $table->decimal('change_returned', 15, 2)->default(0);
                $table->string('payment_status', 20)->default('unpaid')->index();
                $table->string('status', 20)->default('draft')->index();
                $table->string('reference_number')->nullable();
                $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('remarks')->nullable();
                $table->text('terms_and_conditions')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'voucher_number']);
                $table->unique(['business_id', 'invoice_number']);
                $table->index(['business_id', 'invoice_date']);
                $table->index(['business_id', 'customer_id']);
            });
        }

        if (!Schema::hasTable('sales_items')) {
            Schema::create('sales_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_voucher_id')->constrained('sales_vouchers')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->string('barcode_snapshot')->nullable();
                $table->string('product_name_snapshot');
                $table->string('sku_snapshot')->nullable();
                $table->string('hsn_code_snapshot')->nullable();
                $table->decimal('quantity', 15, 3);
                $table->decimal('free_quantity', 15, 3)->default(0);
                $table->decimal('selling_rate', 15, 2)->default(0);
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
                $table->decimal('cost_rate', 15, 2)->nullable();
                $table->foreignId('salesperson_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('remarks')->nullable();
                $table->timestamps();

                $table->index(['sales_voucher_id', 'product_id']);
                $table->index(['product_id', 'batch_id']);
            });
        }

        if (!Schema::hasTable('sales_payments')) {
            Schema::create('sales_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('sales_voucher_id')->constrained('sales_vouchers')->cascadeOnDelete();
                $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
                $table->decimal('amount', 15, 2);
                $table->string('reference_number')->nullable();
                $table->date('payment_date');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'payment_date']);
            });
        }
    }

    private function seedPaymentMethods(): void
    {
        if (!Schema::hasTable('payment_methods')) {
            return;
        }

        foreach (['Cash' => 'cash', 'Card' => 'card', 'UPI' => 'upi', 'Bank Transfer' => 'bank_transfer', 'Cheque' => 'cheque', 'Wallet' => 'wallet', 'Customer Credit' => 'customer_credit'] as $name => $type) {
            DB::table('payment_methods')->updateOrInsert(
                ['business_id' => null, 'type' => $type],
                ['name' => $name, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $names = [
            'view customers', 'create customer', 'edit customer', 'delete customer', 'view sales', 'create sale',
            'edit draft sale', 'confirm sale', 'approve sale', 'cancel sale', 'reverse sale', 'create credit sale',
            'override credit limit', 'apply item discount', 'apply voucher discount', 'override selling price',
            'sell below minimum price', 'sell expired batch', 'view cost price', 'view profit', 'print invoice',
            'receive sale payment',
        ];

        foreach ($names as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['module' => strpos($name, 'customer') !== false ? 'customers' : 'sales', 'description' => ucfirst($name), 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        foreach ([1, 2] as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        // Sales and customer history is retained intentionally.
    }
};
