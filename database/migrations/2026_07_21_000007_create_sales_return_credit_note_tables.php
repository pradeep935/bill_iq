<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales_return_vouchers')) {
            Schema::create('sales_return_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('sales_voucher_id')->nullable()->constrained('sales_vouchers')->nullOnDelete();
                $table->string('voucher_number', 50);
                $table->string('credit_note_number', 50);
                $table->date('return_date');
                $table->string('return_type', 30)->default('against_sale');
                $table->string('tax_type', 20)->default('intrastate');
                $table->unsignedBigInteger('place_of_supply_state_id')->nullable();
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->decimal('cgst_amount', 15, 2)->default(0);
                $table->decimal('sgst_amount', 15, 2)->default(0);
                $table->decimal('igst_amount', 15, 2)->default(0);
                $table->decimal('cess_amount', 15, 2)->default(0);
                $table->decimal('round_off', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->decimal('refund_amount', 15, 2)->default(0);
                $table->decimal('adjustment_amount', 15, 2)->default(0);
                $table->decimal('balance_amount', 15, 2)->default(0);
                $table->string('settlement_type', 30)->default('customer_credit');
                $table->string('status', 20)->default('draft')->index();
                $table->text('reason')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'voucher_number']);
                $table->unique(['business_id', 'credit_note_number']);
                $table->index(['business_id', 'return_date']);
                $table->index(['business_id', 'customer_id']);
                $table->index(['sales_voucher_id', 'status']);
            });
        }

        if (!Schema::hasTable('sales_return_items')) {
            Schema::create('sales_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_return_voucher_id')->constrained('sales_return_vouchers')->cascadeOnDelete();
                $table->foreignId('sales_item_id')->nullable()->constrained('sales_items')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->string('product_name_snapshot');
                $table->string('sku_snapshot')->nullable();
                $table->string('hsn_code_snapshot')->nullable();
                $table->decimal('quantity', 15, 3);
                $table->decimal('selling_rate', 15, 2)->default(0);
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
                $table->text('return_reason')->nullable();
                $table->string('condition_status', 30)->nullable();
                $table->string('restock_status', 30)->default('restock');
                $table->timestamps();

                $table->index(['sales_return_voucher_id', 'product_id'], 'sales_return_items_voucher_product_index');
                $table->index(['sales_item_id', 'product_id'], 'sales_return_items_sale_item_index');
            });
        }

        if (!Schema::hasTable('sales_return_refunds')) {
            Schema::create('sales_return_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('sales_return_voucher_id')->constrained('sales_return_vouchers')->cascadeOnDelete();
                $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
                $table->decimal('amount', 15, 2);
                $table->date('refund_date');
                $table->string('reference_number')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'refund_date']);
            });
        }

        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $names = [
            'view sales returns', 'create sales return', 'edit draft sales return', 'create direct sales return',
            'confirm sales return', 'approve sales return', 'cancel sales return', 'reverse sales return',
            'process refund', 'refund above invoice balance', 'restock damaged goods', 'view return cost',
            'print credit note',
        ];

        foreach ($names as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['module' => 'sales_returns', 'description' => ucfirst($name), 'updated_at' => now(), 'created_at' => now()]
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
        // Sales return and credit note history is retained intentionally.
    }
};
