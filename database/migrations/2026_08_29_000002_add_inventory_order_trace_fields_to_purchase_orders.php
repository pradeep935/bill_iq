<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_orders')) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders', 'source_type')) {
                $table->string('source_type', 40)->default('manual')->after('purchase_requisition_id')->index();
            }
            if (!Schema::hasColumn('purchase_orders', 'source_reference')) {
                $table->string('source_reference', 80)->nullable()->after('source_type');
            }
            if (!Schema::hasColumn('purchase_orders', 'priority')) {
                $table->string('priority', 20)->default('normal')->after('expected_delivery_date');
            }
            if (!Schema::hasColumn('purchase_orders', 'ordered_by')) {
                $table->foreignId('ordered_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('purchase_orders', 'ordered_at')) {
                $table->timestamp('ordered_at')->nullable()->after('ordered_by');
            }
            if (!Schema::hasColumn('purchase_orders', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('ordered_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('purchase_orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            }
        });

        if (Schema::hasTable('purchase_order_items')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_order_items', 'discount_amount')) {
                    $table->decimal('discount_amount', 15, 2)->default(0)->after('purchase_rate');
                }
                if (!Schema::hasColumn('purchase_order_items', 'taxable_amount')) {
                    $table->decimal('taxable_amount', 15, 2)->default(0)->after('discount_amount');
                }
            });
        }
    }

    public function down(): void
    {
        // Data-preserving migration: inventory order trace fields are intentionally retained.
    }
};
