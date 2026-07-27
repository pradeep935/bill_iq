<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opening_stock_vouchers')) {
            Schema::table('opening_stock_vouchers', function (Blueprint $table) {
                if (!Schema::hasColumn('opening_stock_vouchers', 'updated_by')) {
                    $table->unsignedBigInteger('updated_by')->nullable()->after('created_by')->index();
                }

                if (!Schema::hasColumn('opening_stock_vouchers', 'posted_by')) {
                    $table->unsignedBigInteger('posted_by')->nullable()->after('updated_by')->index();
                }

                if (!Schema::hasColumn('opening_stock_vouchers', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('posted_by');
                }

                if (!Schema::hasColumn('opening_stock_vouchers', 'cancelled_by')) {
                    $table->unsignedBigInteger('cancelled_by')->nullable()->after('posted_at')->index();
                }

                if (!Schema::hasColumn('opening_stock_vouchers', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
                }

                if (!Schema::hasColumn('opening_stock_vouchers', 'cancellation_reason')) {
                    $table->text('cancellation_reason')->nullable()->after('cancelled_at');
                }
            });

            DB::table('opening_stock_vouchers')
                ->whereIn('status', ['approved', 'confirmed'])
                ->update([
                    'status' => 'posted',
                    'posted_by' => DB::raw('approved_by'),
                    'posted_at' => DB::raw('approved_at'),
                ]);

            DB::table('opening_stock_vouchers')
                ->where('status', 'reversed')
                ->update(['status' => 'cancelled']);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('opening_stock_vouchers')) {
            return;
        }

        DB::table('opening_stock_vouchers')
            ->where('status', 'posted')
            ->update(['status' => 'approved']);

        DB::table('opening_stock_vouchers')
            ->where('status', 'cancelled')
            ->update(['status' => 'reversed']);
    }
};
