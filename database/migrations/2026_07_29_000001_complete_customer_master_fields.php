<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'blocked_reason')) {
                $table->text('blocked_reason')->nullable()->after('status');
            }

            if (!Schema::hasColumn('customers', 'blocked_at')) {
                $table->timestamp('blocked_at')->nullable()->after('blocked_reason');
            }

            if (!Schema::hasColumn('customers', 'blocked_by')) {
                $table->foreignId('blocked_by')->nullable()->after('blocked_at')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('customers', 'opening_balance_voucher_id')) {
                $table->foreignId('opening_balance_voucher_id')->nullable()->after('opening_balance_type')->constrained('journal_vouchers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Customer financial history is retained intentionally.
    }
};
