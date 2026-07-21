<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->upgradeStockLedger();
        $this->settings();
        $this->reasons();
        $this->adjustments();
        $this->counts();
        $this->transfers();
        $this->locationMovements();
        $this->statusesAndReservations();
        $this->seedDefaults();
        $this->seedPermissions();
    }

    private function upgradeStockLedger(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_ledgers', 'product_variant_id')) $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variant_items')->nullOnDelete();
            if (!Schema::hasColumn('stock_ledgers', 'serial_id')) $table->unsignedBigInteger('serial_id')->nullable()->after('batch_id')->index();
            if (!Schema::hasColumn('stock_ledgers', 'warehouse_location')) $table->string('warehouse_location')->nullable()->after('serial_id')->index();
            if (!Schema::hasColumn('stock_ledgers', 'stock_status')) $table->string('stock_status', 30)->default('saleable')->after('warehouse_location')->index();
            if (!Schema::hasColumn('stock_ledgers', 'running_quantity')) $table->decimal('running_quantity', 15, 3)->nullable()->after('stock_value');
            if (!Schema::hasColumn('stock_ledgers', 'running_value')) $table->decimal('running_value', 15, 2)->nullable()->after('running_quantity');
        });
    }

    private function settings(): void
    {
        if (!Schema::hasTable('business_inventory_settings')) {
            Schema::create('business_inventory_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('valuation_method', 30)->default('weighted_average');
                $table->boolean('negative_stock_allowed')->default(false);
                $table->unsignedInteger('near_expiry_days')->default(30);
                $table->foreignId('transit_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignId('stock_adjustment_loss_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('stock_adjustment_gain_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->timestamps();
                $table->unique('business_id');
            });
        }
    }

    private function reasons(): void
    {
        if (Schema::hasTable('stock_adjustment_reasons')) return;
        Schema::create('stock_adjustment_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reason_code', 50);
            $table->string('reason_name');
            $table->string('default_direction', 10)->default('out');
            $table->string('default_condition_status', 30)->nullable();
            $table->foreignId('accounting_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('approval_required')->default(true);
            $table->string('status', 20)->default('active')->index();
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'reason_code']);
        });
    }

    private function adjustments(): void
    {
        if (!Schema::hasTable('stock_adjustment_vouchers')) {
            Schema::create('stock_adjustment_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('voucher_number', 50);
                $table->date('adjustment_date');
                $table->foreignId('adjustment_reason_id')->nullable()->constrained('stock_adjustment_reasons')->nullOnDelete();
                $table->string('adjustment_type', 20)->default('mixed')->index();
                $table->string('source', 40)->default('manual')->index();
                $table->string('status', 20)->default('draft')->index();
                $table->decimal('total_quantity_in', 15, 3)->default(0);
                $table->decimal('total_quantity_out', 15, 3)->default(0);
                $table->decimal('total_value_in', 15, 2)->default(0);
                $table->decimal('total_value_out', 15, 2)->default(0);
                $table->text('remarks')->nullable();
                $table->foreignId('journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'voucher_number']);
            });
        }

        if (!Schema::hasTable('stock_adjustment_items')) {
            Schema::create('stock_adjustment_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_adjustment_voucher_id')->constrained('stock_adjustment_vouchers')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->unsignedBigInteger('serial_id')->nullable()->index();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->decimal('system_quantity', 15, 3)->default(0);
                $table->decimal('actual_quantity', 15, 3)->nullable();
                $table->decimal('adjustment_quantity', 15, 3);
                $table->string('direction', 10)->index();
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('adjustment_value', 15, 2)->default(0);
                $table->string('warehouse_location')->nullable();
                $table->string('reason')->nullable();
                $table->string('condition_status', 30)->nullable();
                $table->timestamps();
            });
        }
    }

    private function counts(): void
    {
        if (!Schema::hasTable('stock_count_sessions')) {
            Schema::create('stock_count_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('session_number', 50);
                $table->date('count_date');
                $table->string('count_type', 30)->default('full')->index();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->unsignedBigInteger('brand_id')->nullable()->index();
                $table->string('warehouse_location_from')->nullable();
                $table->string('warehouse_location_to')->nullable();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
                $table->boolean('freeze_stock')->default(false);
                $table->string('status', 20)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'session_number']);
            });
        }

        if (!Schema::hasTable('stock_count_items')) {
            Schema::create('stock_count_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_count_session_id')->constrained('stock_count_sessions')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->unsignedBigInteger('serial_id')->nullable()->index();
                $table->decimal('system_quantity', 15, 3)->default(0);
                $table->decimal('counted_quantity', 15, 3)->nullable();
                $table->decimal('variance_quantity', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('variance_value', 15, 2)->default(0);
                $table->string('warehouse_location')->nullable();
                $table->unsignedBigInteger('counted_by')->nullable()->index();
                $table->timestamp('counted_at')->nullable();
                $table->string('review_status', 30)->default('pending')->index();
                $table->text('reviewer_notes')->nullable();
                $table->timestamps();
            });
        }
    }

    private function transfers(): void
    {
        if (!Schema::hasTable('stock_transfer_vouchers')) {
            Schema::create('stock_transfer_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('voucher_number', 50);
                $table->date('transfer_date');
                $table->foreignId('source_branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $table->string('transfer_type', 30)->default('immediate')->index();
                $table->date('expected_delivery_date')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->string('dispatch_reference')->nullable();
                $table->string('vehicle_number')->nullable();
                $table->string('courier_name')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('dispatched_at')->nullable();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'voucher_number']);
            });
        }

        if (!Schema::hasTable('stock_transfer_items')) {
            Schema::create('stock_transfer_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_transfer_voucher_id')->constrained('stock_transfer_vouchers')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('source_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->foreignId('destination_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->decimal('requested_quantity', 15, 3);
                $table->decimal('approved_quantity', 15, 3)->nullable();
                $table->decimal('dispatched_quantity', 15, 3)->default(0);
                $table->decimal('received_quantity', 15, 3)->default(0);
                $table->decimal('rejected_quantity', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->string('source_location')->nullable();
                $table->string('destination_location')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }
    }

    private function locationMovements(): void
    {
        if (!Schema::hasTable('location_transfer_vouchers')) {
            Schema::create('location_transfer_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('voucher_number', 50);
                $table->date('movement_date');
                $table->string('status', 20)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'voucher_number']);
            });
        }
        if (!Schema::hasTable('location_transfer_items')) {
            Schema::create('location_transfer_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('location_transfer_voucher_id')->constrained('location_transfer_vouchers')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->decimal('quantity', 15, 3);
                $table->string('from_location');
                $table->string('to_location');
                $table->timestamps();
            });
        }
    }

    private function statusesAndReservations(): void
    {
        if (!Schema::hasTable('inventory_stock_statuses')) {
            Schema::create('inventory_stock_statuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('companies')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name');
                $table->boolean('is_saleable')->default(false);
                $table->boolean('is_system')->default(false);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique(['business_id', 'code']);
            });
        }
        if (!Schema::hasTable('stock_reservations')) {
            Schema::create('stock_reservations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->string('reference_type');
                $table->unsignedBigInteger('reference_id');
                $table->decimal('reserved_quantity', 15, 3);
                $table->decimal('fulfilled_quantity', 15, 3)->default(0);
                $table->decimal('released_quantity', 15, 3)->default(0);
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'reference_type', 'reference_id'], 'stock_reservation_ref_index');
            });
        }
    }

    private function seedDefaults(): void
    {
        $reasons = [
            ['PCI', 'Physical Count Increase', 'in', 'saleable'], ['PCS', 'Physical Count Shortage', 'out', 'lost'],
            ['DMG', 'Damaged Goods', 'out', 'damaged'], ['EXP', 'Expired Goods', 'out', 'expired'],
            ['LOST', 'Lost Stock', 'out', 'lost'], ['STLN', 'Stolen Stock', 'out', 'lost'],
            ['CORR', 'Data Correction', 'in', 'saleable'], ['QREJ', 'Quality Rejection', 'out', 'quarantined'],
            ['SAMP', 'Sample Distribution', 'out', 'saleable'], ['INTC', 'Internal Consumption', 'out', 'saleable'],
            ['PROMO', 'Promotional Giveaway', 'out', 'saleable'], ['BRKG', 'Warehouse Breakage', 'out', 'damaged'],
        ];
        $statuses = [
            ['saleable', 'Saleable', true], ['damaged', 'Damaged', false], ['expired', 'Expired', false],
            ['defective', 'Defective', false], ['quarantined', 'Quarantined', false], ['blocked', 'Blocked', false],
            ['returned', 'Returned', false], ['in_transit', 'In Transit', false],
        ];
        foreach (DB::table('companies')->get(['id']) as $company) {
            foreach ($reasons as $reason) {
                DB::table('stock_adjustment_reasons')->updateOrInsert(
                    ['business_id' => $company->id, 'reason_code' => $reason[0]],
                    ['reason_name' => $reason[1], 'default_direction' => $reason[2], 'default_condition_status' => $reason[3], 'approval_required' => true, 'is_system' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
                );
            }
            foreach ($statuses as $status) {
                DB::table('inventory_stock_statuses')->updateOrInsert(
                    ['business_id' => $company->id, 'code' => $status[0]],
                    ['name' => $status[1], 'is_saleable' => $status[2], 'is_system' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
                );
            }
            DB::table('business_inventory_settings')->updateOrInsert(['business_id' => $company->id], ['valuation_method' => 'weighted_average', 'negative_stock_allowed' => false, 'near_expiry_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) return;
        $names = ['view stock adjustments','create stock adjustment','submit stock adjustment','approve stock adjustment','post stock adjustment','reverse stock adjustment','manage adjustment reasons','view stock counts','create stock count','perform stock count','submit stock count','review stock count','approve stock count','post stock count variance','view stock transfers','create stock transfer','approve stock transfer','dispatch stock transfer','receive stock transfer','cancel stock transfer','reverse stock transfer','perform location transfer','manage damaged stock','manage expired stock','manage quarantine stock','override excess receipt','view stock cost','view inventory valuation','view inventory reports','view inventory dashboard','sell or move expired stock','reopen stock count'];
        foreach ($names as $name) DB::table('permissions')->updateOrInsert(['name' => $name], ['module' => 'inventory', 'description' => ucfirst($name), 'created_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        foreach ([1, 2] as $roleId) foreach ($ids as $id) DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Inventory control history is retained intentionally.
    }
};
