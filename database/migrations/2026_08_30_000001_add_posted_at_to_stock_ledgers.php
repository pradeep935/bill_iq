<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_ledgers', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('transaction_date')->index();
            }
        });

        DB::table('stock_ledgers')
            ->whereNull('posted_at')
            ->whereNotNull('created_at')
            ->update(['posted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('stock_ledgers', function (Blueprint $table) {
            if (Schema::hasColumn('stock_ledgers', 'posted_at')) {
                $table->dropColumn('posted_at');
            }
        });
    }
};
