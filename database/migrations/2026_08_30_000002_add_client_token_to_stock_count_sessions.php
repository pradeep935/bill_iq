<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_count_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('stock_count_sessions', 'client_token')) {
                $table->string('client_token', 80)->nullable()->after('session_number');
                $table->unique(['business_id', 'client_token'], 'stock_count_sessions_client_token_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('stock_count_sessions', 'client_token')) {
                $table->dropUnique('stock_count_sessions_client_token_unique');
                $table->dropColumn('client_token');
            }
        });
    }
};
