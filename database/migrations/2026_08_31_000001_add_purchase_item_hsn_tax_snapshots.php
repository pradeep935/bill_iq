<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_items')) {
            return;
        }

        Schema::table('purchase_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_items', 'hsn_code_snapshot')) {
                $table->string('hsn_code_snapshot', 20)->nullable()->after('unit_id');
            }

            if (!Schema::hasColumn('purchase_items', 'hsn_code_type_snapshot')) {
                $table->string('hsn_code_type_snapshot', 10)->nullable()->after('hsn_code_snapshot');
            }

            if (!Schema::hasColumn('purchase_items', 'hsn_description_snapshot')) {
                $table->text('hsn_description_snapshot')->nullable()->after('hsn_code_type_snapshot');
            }

            if (!Schema::hasColumn('purchase_items', 'hsn_tax_rate_id')) {
                $table->unsignedBigInteger('hsn_tax_rate_id')->nullable()->after('hsn_description_snapshot')->index();
            }

            if (!Schema::hasColumn('purchase_items', 'taxability_snapshot')) {
                $table->string('taxability_snapshot', 20)->nullable()->after('hsn_tax_rate_id')->index();
            }

            if (!Schema::hasColumn('purchase_items', 'tax_source')) {
                $table->string('tax_source', 40)->nullable()->after('taxability_snapshot')->index();
            }

            if (!Schema::hasColumn('purchase_items', 'notification_number')) {
                $table->string('notification_number')->nullable()->after('tax_source');
            }

            if (!Schema::hasColumn('purchase_items', 'tax_rule_description')) {
                $table->text('tax_rule_description')->nullable()->after('notification_number');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('purchase_items')) {
            return;
        }

        Schema::table('purchase_items', function (Blueprint $table) {
            foreach ([
                'tax_rule_description',
                'notification_number',
                'tax_source',
                'taxability_snapshot',
                'hsn_tax_rate_id',
                'hsn_description_snapshot',
                'hsn_code_type_snapshot',
                'hsn_code_snapshot',
            ] as $column) {
                if (Schema::hasColumn('purchase_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
