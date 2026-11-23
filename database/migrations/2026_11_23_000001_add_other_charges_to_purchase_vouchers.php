<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_vouchers', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_vouchers', 'other_charges')) {
                $table->decimal('other_charges', 14, 2)->default(0)->after('discount_amount');
            }
            if (!Schema::hasColumn('purchase_vouchers', 'other_charges_gst_rate')) {
                $table->decimal('other_charges_gst_rate', 5, 2)->default(0)->after('other_charges');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vouchers', function (Blueprint $table) {
            $table->dropColumn(['other_charges', 'other_charges_gst_rate']);
        });
    }
};
