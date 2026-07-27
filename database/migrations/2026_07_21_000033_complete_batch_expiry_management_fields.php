<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_batches')) {
            return;
        }

        Schema::table('product_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('product_batches', 'business_id')) {
                $table->unsignedBigInteger('business_id')->nullable()->after('id')->index();
            }

            if (!Schema::hasColumn('product_batches', 'batch_number')) {
                $table->string('batch_number', 100)->nullable()->after('batch_no')->index();
            }

            if (!Schema::hasColumn('product_batches', 'manufacturing_date')) {
                $table->date('manufacturing_date')->nullable()->after('batch_number');
            }

            if (!Schema::hasColumn('product_batches', 'blocked_reason')) {
                $table->text('blocked_reason')->nullable()->after('status');
            }

            if (!Schema::hasColumn('product_batches', 'blocked_by')) {
                $table->unsignedBigInteger('blocked_by')->nullable()->after('blocked_reason')->index();
            }

            if (!Schema::hasColumn('product_batches', 'blocked_at')) {
                $table->timestamp('blocked_at')->nullable()->after('blocked_by');
            }

            if (!Schema::hasColumn('product_batches', 'unblocked_by')) {
                $table->unsignedBigInteger('unblocked_by')->nullable()->after('blocked_at')->index();
            }

            if (!Schema::hasColumn('product_batches', 'unblocked_at')) {
                $table->timestamp('unblocked_at')->nullable()->after('unblocked_by');
            }

            if (!Schema::hasColumn('product_batches', 'posted_by')) {
                $table->unsignedBigInteger('posted_by')->nullable()->after('unblocked_at')->index();
            }

            if (!Schema::hasColumn('product_batches', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('posted_by');
            }

            if (!Schema::hasColumn('product_batches', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('posted_at')->index();
            }
        });

        if (Schema::hasColumn('product_batches', 'tenant_id')) {
            DB::table('product_batches')->whereNull('business_id')->update(['business_id' => DB::raw('tenant_id')]);
        }

        DB::table('product_batches')
            ->whereNull('batch_number')
            ->update(['batch_number' => DB::raw('batch_no')]);

        if (Schema::hasColumn('product_batches', 'mfg_date')) {
            DB::table('product_batches')->whereNull('manufacturing_date')->update(['manufacturing_date' => DB::raw('mfg_date')]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('product_batches')) {
            return;
        }

        Schema::table('product_batches', function (Blueprint $table) {
            foreach (['updated_by', 'posted_at', 'posted_by', 'unblocked_at', 'unblocked_by', 'blocked_at', 'blocked_by', 'blocked_reason', 'manufacturing_date', 'batch_number', 'business_id'] as $column) {
                if (Schema::hasColumn('product_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
