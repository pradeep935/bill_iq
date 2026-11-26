<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** BillIQ Network: one business sends a sales bill, the other turns it into a purchase. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('network_connections')) {
            Schema::create('network_connections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('requester_business_id');
                $table->unsignedBigInteger('receiver_business_id');
                $table->string('status', 20)->default('pending');
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->unsignedBigInteger('responded_by')->nullable();
                $table->timestamps();
                $table->unique(['requester_business_id', 'receiver_business_id'], 'network_connection_pair');
                $table->index('receiver_business_id');
            });
        }

        if (!Schema::hasTable('network_bills')) {
            Schema::create('network_bills', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('from_business_id');
                $table->unsignedBigInteger('to_business_id');
                $table->unsignedBigInteger('sales_voucher_id');
                $table->string('status', 20)->default('sent');
                $table->unsignedBigInteger('sent_by')->nullable();
                $table->timestamps();
                $table->unique(['to_business_id', 'sales_voucher_id'], 'network_bill_once');
            });
        }

        // Which purchase was made from which received bill, so one bill is not entered twice.
        if (!Schema::hasTable('network_imports')) {
            Schema::create('network_imports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('from_business_id');
                $table->unsignedBigInteger('sales_voucher_id');
                $table->unsignedBigInteger('purchase_voucher_id');
                $table->timestamps();
                $table->index(['business_id', 'sales_voucher_id'], 'network_import_source');
                $table->index('purchase_voucher_id');
            });
        }

        // The buyer's own product for each product of a seller, remembered for the next bill.
        if (!Schema::hasTable('network_product_maps')) {
            Schema::create('network_product_maps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('from_business_id');
                $table->unsignedBigInteger('from_product_id');
                $table->unsignedBigInteger('product_id');
                $table->timestamps();
                $table->unique(['business_id', 'from_business_id', 'from_product_id'], 'network_product_map_once');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('network_product_maps');
        Schema::dropIfExists('network_imports');
        Schema::dropIfExists('network_bills');
        Schema::dropIfExists('network_connections');
    }
};
