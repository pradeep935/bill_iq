<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->products();
        $this->serials();
        $this->barcodes();
        $this->manufacturing();
        $this->permissions();
    }

    private function products(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'tracking_type')) {
                $table->string('tracking_type', 30)->default('none')->after('track_inventory');
            }
            if (!Schema::hasColumn('products', 'batch_required')) {
                $table->boolean('batch_required')->default(false)->after('tracking_type');
            }
            if (!Schema::hasColumn('products', 'serial_required')) {
                $table->boolean('serial_required')->default(false)->after('batch_required');
            }
        });

        DB::table('products')
            ->where(function ($query) {
                $query->where('batch_required', true)->orWhere('serial_required', true);
            })
            ->update([
                'tracking_type' => DB::raw("CASE WHEN batch_required = 1 AND serial_required = 1 THEN 'batch_serial' WHEN batch_required = 1 THEN 'batch' WHEN serial_required = 1 THEN 'serial' ELSE tracking_type END"),
            ]);
    }

    private function serials(): void
    {
        if (!Schema::hasTable('product_serial_numbers')) {
            Schema::create('product_serial_numbers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('product_variant_id')->nullable()->index();
                $table->unsignedBigInteger('batch_id')->nullable()->index();
                $table->string('serial_number', 120);
                $table->string('normalized_serial_number', 120)->index();
                $table->string('secondary_serial_number', 120)->nullable();
                $table->string('imei_1', 80)->nullable()->index();
                $table->string('imei_2', 80)->nullable()->index();
                $table->string('purchase_reference')->nullable();
                $table->string('sale_reference')->nullable();
                $table->string('status', 40)->default('in_stock')->index();
                $table->string('current_status', 40)->default('in_stock')->index();
                $table->string('condition', 40)->default('new')->index();
                $table->date('purchase_date')->nullable();
                $table->date('warranty_expiry_date')->nullable()->index();
                $table->timestamp('sold_at')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'product_id', 'normalized_serial_number'], 'serial_business_product_unique');
            });
        } else {
            Schema::table('product_serial_numbers', function (Blueprint $table) {
                foreach ([
                    'branch_id' => fn () => $table->unsignedBigInteger('branch_id')->nullable()->after('business_id')->index(),
                    'warehouse_id' => fn () => $table->unsignedBigInteger('warehouse_id')->nullable()->after('branch_id')->index(),
                    'product_variant_id' => fn () => $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id')->index(),
                    'normalized_serial_number' => fn () => $table->string('normalized_serial_number', 120)->nullable()->after('serial_number')->index(),
                    'secondary_serial_number' => fn () => $table->string('secondary_serial_number', 120)->nullable()->after('normalized_serial_number'),
                    'imei_1' => fn () => $table->string('imei_1', 80)->nullable()->after('secondary_serial_number')->index(),
                    'imei_2' => fn () => $table->string('imei_2', 80)->nullable()->after('imei_1')->index(),
                    'purchase_reference' => fn () => $table->string('purchase_reference')->nullable()->after('imei_2'),
                    'sale_reference' => fn () => $table->string('sale_reference')->nullable()->after('purchase_reference'),
                    'current_status' => fn () => $table->string('current_status', 40)->default('in_stock')->after('sale_reference')->index(),
                    'condition' => fn () => $table->string('condition', 40)->default('new')->after('current_status')->index(),
                    'purchase_date' => fn () => $table->date('purchase_date')->nullable()->after('condition'),
                    'warranty_expiry_date' => fn () => $table->date('warranty_expiry_date')->nullable()->after('purchase_date')->index(),
                    'sold_at' => fn () => $table->timestamp('sold_at')->nullable()->after('warranty_expiry_date'),
                    'customer_id' => fn () => $table->unsignedBigInteger('customer_id')->nullable()->after('sold_at')->index(),
                    'remarks' => fn () => $table->text('remarks')->nullable()->after('customer_id'),
                    'created_by' => fn () => $table->unsignedBigInteger('created_by')->nullable()->after('remarks')->index(),
                    'updated_by' => fn () => $table->unsignedBigInteger('updated_by')->nullable()->after('created_by')->index(),
                    'deleted_at' => fn () => $table->softDeletes(),
                ] as $column => $callback) {
                    if (!Schema::hasColumn('product_serial_numbers', $column)) {
                        $callback();
                    }
                }
            });
            DB::table('product_serial_numbers')->whereNull('normalized_serial_number')->update(['normalized_serial_number' => DB::raw('UPPER(REPLACE(REPLACE(TRIM(serial_number), " ", ""), "-", ""))')]);
            DB::table('product_serial_numbers')->whereNull('current_status')->update(['current_status' => DB::raw("COALESCE(status, 'in_stock')")]);
        }

        if (!Schema::hasTable('serial_number_histories')) {
            Schema::create('serial_number_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('serial_number_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->string('event_type', 60)->index();
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->string('voucher_type')->nullable();
                $table->unsignedBigInteger('voucher_id')->nullable()->index();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    private function barcodes(): void
    {
        if (Schema::hasTable('product_barcodes')) {
            Schema::table('product_barcodes', function (Blueprint $table) {
                foreach ([
                    'product_variant_id' => fn () => $table->unsignedBigInteger('product_variant_id')->nullable()->after('product_id')->index(),
                    'batch_id' => fn () => $table->unsignedBigInteger('batch_id')->nullable()->after('product_variant_id')->index(),
                    'serial_number_id' => fn () => $table->unsignedBigInteger('serial_number_id')->nullable()->after('batch_id')->index(),
                    'format' => fn () => $table->string('format', 30)->default('CODE128')->after('barcode'),
                    'barcode_type' => fn () => $table->string('barcode_type', 40)->default('internal')->after('format')->index(),
                    'source' => fn () => $table->string('source', 40)->default('manual')->after('barcode_type')->index(),
                    'is_active' => fn () => $table->boolean('is_active')->default(true)->after('is_primary')->index(),
                    'created_by' => fn () => $table->unsignedBigInteger('created_by')->nullable()->after('status')->index(),
                    'updated_by' => fn () => $table->unsignedBigInteger('updated_by')->nullable()->after('created_by')->index(),
                ] as $column => $callback) {
                    if (!Schema::hasColumn('product_barcodes', $column)) {
                        $callback();
                    }
                }
            });
        }

        if (!Schema::hasTable('barcode_histories')) {
            Schema::create('barcode_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('product_barcode_id')->nullable()->index();
                $table->string('barcode', 120)->index();
                $table->string('event_type', 60)->index();
                $table->json('meta')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('barcode_label_prints')) {
            Schema::create('barcode_label_prints', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('product_barcode_id')->nullable()->index();
                $table->string('barcode', 120);
                $table->string('template', 80)->default('50x25');
                $table->unsignedInteger('labels_count')->default(1);
                $table->json('settings')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    private function manufacturing(): void
    {
        if (!Schema::hasTable('boms')) {
            Schema::create('boms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('finished_product_id')->index();
                $table->unsignedBigInteger('finished_product_variant_id')->nullable()->index();
                $table->string('bom_code', 60);
                $table->string('bom_name');
                $table->unsignedInteger('version')->default(1);
                $table->decimal('output_quantity', 15, 3)->default(1);
                $table->unsignedBigInteger('unit_id')->nullable()->index();
                $table->decimal('wastage_percentage', 8, 3)->default(0);
                $table->string('status', 30)->default('draft')->index();
                $table->date('effective_from')->nullable()->index();
                $table->date('effective_to')->nullable()->index();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->unsignedBigInteger('approved_by')->nullable()->index();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'bom_code']);
                $table->index(['business_id', 'finished_product_id', 'status'], 'boms_product_status_index');
            });
        }

        if (!Schema::hasTable('bom_items')) {
            Schema::create('bom_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bom_id')->index();
                $table->unsignedBigInteger('raw_material_product_id')->index();
                $table->unsignedBigInteger('raw_material_variant_id')->nullable()->index();
                $table->decimal('quantity_required', 15, 3);
                $table->unsignedBigInteger('unit_id')->nullable()->index();
                $table->decimal('wastage_percentage', 8, 3)->default(0);
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->string('batch_selection_method', 30)->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('production_orders')) {
            Schema::create('production_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->string('order_number', 60);
                $table->unsignedBigInteger('bom_id')->index();
                $table->unsignedInteger('bom_version')->default(1);
                $table->unsignedBigInteger('finished_product_id')->index();
                $table->unsignedBigInteger('finished_product_variant_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('source_warehouse_id')->index();
                $table->unsignedBigInteger('finished_goods_warehouse_id')->index();
                $table->decimal('planned_quantity', 15, 3);
                $table->decimal('produced_quantity', 15, 3)->default(0);
                $table->decimal('rejected_quantity', 15, 3)->default(0);
                $table->date('start_date')->nullable();
                $table->date('expected_completion_date')->nullable();
                $table->timestamp('actual_completion_date')->nullable();
                $table->string('status', 40)->default('draft')->index();
                $table->unsignedBigInteger('assigned_user_id')->nullable()->index();
                $table->text('notes')->nullable();
                $table->decimal('additional_cost', 15, 2)->default(0);
                $table->decimal('production_cost', 15, 2)->default(0);
                $table->decimal('cost_per_unit', 15, 2)->default(0);
                $table->unsignedBigInteger('finished_batch_id')->nullable()->index();
                $table->date('manufacturing_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('approved_by')->nullable()->index();
                $table->unsignedBigInteger('completed_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'order_number']);
            });
        }

        if (!Schema::hasTable('production_order_items')) {
            Schema::create('production_order_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('production_order_id')->index();
                $table->unsignedBigInteger('raw_material_product_id')->index();
                $table->unsignedBigInteger('raw_material_variant_id')->nullable()->index();
                $table->unsignedBigInteger('batch_id')->nullable()->index();
                $table->decimal('required_quantity', 15, 3);
                $table->decimal('reserved_quantity', 15, 3)->default(0);
                $table->decimal('consumed_quantity', 15, 3)->default(0);
                $table->decimal('wastage_quantity', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('total_cost', 15, 2)->default(0);
                $table->string('availability_status', 40)->default('unchecked')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('production_wastages')) {
            Schema::create('production_wastages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('production_order_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('product_variant_id')->nullable()->index();
                $table->unsignedBigInteger('batch_id')->nullable()->index();
                $table->decimal('quantity', 15, 3);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('total_cost', 15, 2)->default(0);
                $table->string('reason', 120)->nullable();
                $table->timestamps();
            });
        }
    }

    private function permissions(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $names = [
            'serial.view', 'serial.create', 'serial.update', 'serial.delete', 'serial.transfer', 'serial.status', 'serial.print', 'serial.export',
            'barcode.view', 'barcode.create', 'barcode.update', 'barcode.delete', 'barcode.generate', 'barcode.print', 'barcode.import', 'barcode.export',
            'manufacturing.view', 'manufacturing.create_bom', 'manufacturing.update_bom', 'manufacturing.approve_bom', 'manufacturing.create_order',
            'manufacturing.update_order', 'manufacturing.reserve_materials', 'manufacturing.start_order', 'manufacturing.post_order',
            'manufacturing.cancel_order', 'manufacturing.print', 'manufacturing.export',
        ];

        foreach ($names as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['module' => str_contains($name, '.') ? explode('.', $name)[0] : 'inventory', 'description' => ucwords(str_replace(['.', '_'], ' ', $name)), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        if (Schema::hasTable('role_permissions')) {
            $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
            foreach ([1, 2] as $roleId) {
                foreach ($ids as $id) {
                    DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Inventory module data is retained intentionally.
    }
};
