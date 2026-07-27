<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_batches')) {
            Schema::table('product_batches', function (Blueprint $table) {
                if (!Schema::hasColumn('product_batches', 'lot_number')) $table->string('lot_number', 100)->nullable()->after('batch_number')->index();
                if (!Schema::hasColumn('product_batches', 'condition_status')) $table->string('condition_status', 30)->default('saleable')->after('status')->index();
                if (!Schema::hasColumn('product_batches', 'parent_batch_id')) $table->unsignedBigInteger('parent_batch_id')->nullable()->after('product_id')->index();
                if (!Schema::hasColumn('product_batches', 'source_voucher_type')) $table->string('source_voucher_type')->nullable()->after('posted_at');
                if (!Schema::hasColumn('product_batches', 'source_voucher_id')) $table->unsignedBigInteger('source_voucher_id')->nullable()->after('source_voucher_type')->index();
                if (!Schema::hasColumn('product_batches', 'supplier_id')) $table->unsignedBigInteger('supplier_id')->nullable()->after('source_voucher_id')->index();
                if (!Schema::hasColumn('product_batches', 'quarantined_by')) $table->unsignedBigInteger('quarantined_by')->nullable()->after('unblocked_at')->index();
                if (!Schema::hasColumn('product_batches', 'quarantined_at')) $table->timestamp('quarantined_at')->nullable()->after('quarantined_by');
                if (!Schema::hasColumn('product_batches', 'released_by')) $table->unsignedBigInteger('released_by')->nullable()->after('quarantined_at')->index();
                if (!Schema::hasColumn('product_batches', 'released_at')) $table->timestamp('released_at')->nullable()->after('released_by');
                if (!Schema::hasColumn('product_batches', 'release_outcome')) $table->string('release_outcome', 40)->nullable()->after('released_at');
            });
        }

        if (!Schema::hasTable('batch_histories')) {
            Schema::create('batch_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('batch_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->string('event_type', 60)->index();
                $table->string('voucher_type')->nullable();
                $table->unsignedBigInteger('voucher_id')->nullable()->index();
                $table->decimal('quantity', 12, 3)->default(0);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->string('from_condition', 40)->nullable();
                $table->string('to_condition', 40)->nullable();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('permissions')) {
            $names = [
                'batch.view', 'batch.create', 'batch.edit_draft', 'batch.view_ledger', 'batch.print_label',
                'batch.export', 'batch.block', 'batch.unblock', 'batch.quarantine', 'batch.release_quarantine',
                'batch.transfer', 'batch.split', 'batch.merge', 'batch.view_cost', 'batch.view_audit',
            ];

            foreach ($names as $name) {
                DB::table('permissions')->updateOrInsert(
                    ['name' => $name],
                    ['module' => 'inventory', 'description' => ucwords(str_replace(['.', '_'], ' ', $name)), 'created_at' => now(), 'updated_at' => now()]
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
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_histories');
    }
};
