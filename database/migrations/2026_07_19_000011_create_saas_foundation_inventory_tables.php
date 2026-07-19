<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('monthly_price', 12, 2)->default(0);
            $table->decimal('yearly_price', 12, 2)->default(0);
            $table->unsignedInteger('trial_days')->default(0);
            $table->unsignedInteger('user_limit')->default(1);
            $table->unsignedInteger('branch_limit')->default(1);
            $table->unsignedInteger('warehouse_limit')->default(1);
            $table->unsignedInteger('invoice_limit')->default(0);
            $table->json('feature_limits')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('business_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->string('billing_cycle', 20)->default('monthly');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->date('trial_ends_at')->nullable();
            $table->string('status', 30)->default('trial')->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('module', 80)->index();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('role_id')->index();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('warehouse_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('zone')->nullable();
            $table->string('aisle')->nullable();
            $table->string('rack')->nullable();
            $table->string('shelf')->nullable();
            $table->string('bin')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('warehouse_product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('warehouse_location_id')->nullable()->constrained('warehouse_locations')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 12, 3)->default(0);
            $table->decimal('reserved_quantity', 12, 3)->default(0);
            $table->decimal('available_quantity', 12, 3)->default(0);
            $table->decimal('average_cost', 12, 2)->default(0);
            $table->decimal('stock_value', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'branch_id', 'warehouse_id', 'warehouse_location_id', 'product_id'], 'warehouse_product_stock_unique');
        });

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('count_no')->index();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_count_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30)->default('assigned')->index();
            $table->timestamps();
        });

        Schema::create('inventory_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('system_quantity', 12, 3)->default(0);
            $table->decimal('counted_quantity', 12, 3)->default(0);
            $table->decimal('difference_quantity', 12, 3)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('inventory_count_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $table->foreignId('inventory_count_item_id')->nullable()->constrained('inventory_count_items')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('barcode')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamp('scanned_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 40)->default('cash');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('inventory_count_scans');
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_count_assignments');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('warehouse_product_stocks');
        Schema::dropIfExists('warehouse_locations');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('business_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
