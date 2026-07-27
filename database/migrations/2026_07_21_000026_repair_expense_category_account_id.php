<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('expense_categories') || Schema::hasColumn('expense_categories', 'account_id')) {
            return;
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')->nullable();
        });
    }

    public function down(): void
    {
        // Preserve compatibility column.
    }
};
