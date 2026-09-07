<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('opening_stock_items', 'condition_status')) {
            Schema::table('opening_stock_items', fn (Blueprint $t) => $t->string('condition_status', 30)->default('saleable'));
        }
        if (! Schema::hasColumn('batch_histories', 'operation_token')) {
            Schema::table('batch_histories', function (Blueprint $t) {
                $t->uuid('operation_token')->nullable();
                $t->string('request_hash', 64)->nullable();
                $t->unique(['business_id', 'operation_token'], 'batch_operation_idempotency');
            });
        }
    }

    public function down(): void
    {
        // Preserve posting identities and opening-stock conditions in audit history.
    }
};
