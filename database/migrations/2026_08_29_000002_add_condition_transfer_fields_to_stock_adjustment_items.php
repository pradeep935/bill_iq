<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_adjustment_items', 'source_condition_status')) {
                $table->string('source_condition_status', 30)->nullable()->after('condition_status')->index();
            }

            if (!Schema::hasColumn('stock_adjustment_items', 'destination_condition_status')) {
                $table->string('destination_condition_status', 30)->nullable()->after('source_condition_status')->index();
            }
        });

        if (Schema::hasTable('businesses') && Schema::hasTable('stock_adjustment_reasons')) {
            $now = now();
            $businessIds = DB::table('businesses')->pluck('id');

            foreach ($businessIds as $businessId) {
                foreach ([
                    ['RECOVERED', 'Recovered / Repaired Stock'],
                    ['RETURN_TO_SALE', 'Returned to Saleable Stock'],
                ] as [$code, $name]) {
                    DB::table('stock_adjustment_reasons')->updateOrInsert(
                        ['business_id' => $businessId, 'reason_code' => $code],
                        [
                            'reason_name' => $name,
                            'default_direction' => 'in',
                            'default_condition_status' => 'saleable',
                            'approval_required' => true,
                            'is_system' => true,
                            'status' => 'active',
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            if (Schema::hasColumn('stock_adjustment_items', 'destination_condition_status')) {
                $table->dropColumn('destination_condition_status');
            }

            if (Schema::hasColumn('stock_adjustment_items', 'source_condition_status')) {
                $table->dropColumn('source_condition_status');
            }
        });
    }
};
