<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_return_vouchers')) {
            Schema::create('purchase_return_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->foreignId('purchase_voucher_id')->nullable()->constrained('purchase_vouchers')->nullOnDelete();
                $table->string('voucher_number', 40);
                $table->date('return_date');
                $table->string('supplier_debit_note_number')->nullable();
                $table->text('reason')->nullable();
                $table->string('return_type', 30)->default('against_purchase');
                $table->string('tax_type', 20)->default('intrastate');
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->decimal('cgst_amount', 15, 2)->default(0);
                $table->decimal('sgst_amount', 15, 2)->default(0);
                $table->decimal('igst_amount', 15, 2)->default(0);
                $table->decimal('cess_amount', 15, 2)->default(0);
                $table->decimal('round_off', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->string('settlement_type', 30)->default('supplier_credit');
                $table->decimal('settlement_amount', 15, 2)->default(0);
                $table->decimal('balance_amount', 15, 2)->default(0);
                $table->string('status', 20)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'voucher_number']);
                $table->index(['business_id', 'return_date']);
                $table->index(['business_id', 'supplier_id']);
                $table->index(['purchase_voucher_id', 'status']);
            });
        }

        if (!Schema::hasTable('purchase_return_items')) {
            Schema::create('purchase_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_return_voucher_id')->constrained('purchase_return_vouchers')->cascadeOnDelete();
                $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->decimal('quantity', 15, 3);
                $table->decimal('purchase_rate', 15, 2)->default(0);
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
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->index(['purchase_return_voucher_id', 'product_id'], 'purchase_return_items_voucher_product_index');
                $table->index(['purchase_item_id', 'product_id'], 'purchase_return_items_purchase_item_index');
            });
        }

        $this->seedPermissions();
    }

    public function down(): void
    {
        // Purchase return history is retained intentionally.
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $permissions = [
            ['name' => 'view purchase returns', 'module' => 'purchase_returns', 'description' => 'View purchase return vouchers'],
            ['name' => 'create purchase return', 'module' => 'purchase_returns', 'description' => 'Create purchase return vouchers'],
            ['name' => 'edit draft purchase return', 'module' => 'purchase_returns', 'description' => 'Edit draft purchase return vouchers'],
            ['name' => 'create direct purchase return', 'module' => 'purchase_returns', 'description' => 'Create purchase returns without an original purchase voucher'],
            ['name' => 'confirm purchase return', 'module' => 'purchase_returns', 'description' => 'Confirm purchase return vouchers'],
            ['name' => 'approve purchase return', 'module' => 'purchase_returns', 'description' => 'Approve and post purchase return vouchers'],
            ['name' => 'cancel purchase return', 'module' => 'purchase_returns', 'description' => 'Cancel draft purchase return vouchers'],
            ['name' => 'reverse purchase return', 'module' => 'purchase_returns', 'description' => 'Reverse posted purchase return vouchers'],
            ['name' => 'view purchase return cost', 'module' => 'purchase_returns', 'description' => 'View purchase return cost fields'],
            ['name' => 'view supplier settlement', 'module' => 'purchase_returns', 'description' => 'View purchase return supplier settlement fields'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                array_merge($permission, ['updated_at' => now(), 'created_at' => now()])
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column($permissions, 'name'))
            ->pluck('id');

        foreach ([1, 2] as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }
};
