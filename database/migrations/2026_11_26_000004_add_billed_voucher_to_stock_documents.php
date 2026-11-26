<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which bill a goods receipt or delivery challan was billed on, so the same goods are counted once. */
return new class extends Migration {
    public function up(): void
    {
        foreach (['goods_receipts', 'delivery_challans'] as $name) {
            if (Schema::hasTable($name) && !Schema::hasColumn($name, 'billed_voucher_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->unsignedBigInteger('billed_voucher_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['goods_receipts', 'delivery_challans'] as $name) {
            if (Schema::hasColumn($name, 'billed_voucher_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('billed_voucher_id'));
            }
        }
    }
};
