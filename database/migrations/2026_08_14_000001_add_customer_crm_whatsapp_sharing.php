<?php

use App\Services\MobileNumberService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (!Schema::hasColumn('customers', 'normalized_mobile')) {
                    $table->string('normalized_mobile', 20)->nullable()->after('mobile');
                }
                if (!Schema::hasColumn('customers', 'whatsapp_number')) {
                    $table->string('whatsapp_number', 30)->nullable()->after('normalized_mobile');
                }
                if (!Schema::hasColumn('customers', 'whatsapp_same_as_mobile')) {
                    $table->boolean('whatsapp_same_as_mobile')->default(true)->after('whatsapp_number');
                }
            });

            $normalizer = app(MobileNumberService::class);
            DB::table('customers')
                ->select(['id', 'mobile', 'phone', 'whatsapp_number'])
                ->orderBy('id')
                ->chunkById(200, function ($customers) use ($normalizer) {
                    foreach ($customers as $customer) {
                        $normalized = $normalizer->normalize($customer->mobile ?: $customer->phone);
                        $whatsapp = $normalizer->normalize($customer->whatsapp_number ?: $customer->mobile);

                        DB::table('customers')->where('id', $customer->id)->update([
                            'normalized_mobile' => $normalized,
                            'whatsapp_number' => $whatsapp,
                            'whatsapp_same_as_mobile' => blank($customer->whatsapp_number) || $whatsapp === $normalized,
                        ]);
                    }
                });

            Schema::table('customers', function (Blueprint $table) {
                $table->index(['business_id', 'normalized_mobile'], 'customers_business_normalized_mobile_idx');
            });
        }

        if (Schema::hasTable('sales_vouchers')) {
            Schema::table('sales_vouchers', function (Blueprint $table) {
                if (!Schema::hasColumn('sales_vouchers', 'public_token')) {
                    $table->string('public_token', 80)->nullable()->after('terms_and_conditions');
                }
                if (!Schema::hasColumn('sales_vouchers', 'public_share_enabled')) {
                    $table->boolean('public_share_enabled')->default(false)->after('public_token');
                }
                if (!Schema::hasColumn('sales_vouchers', 'public_token_created_at')) {
                    $table->timestamp('public_token_created_at')->nullable()->after('public_share_enabled');
                }
            });

            Schema::table('sales_vouchers', function (Blueprint $table) {
                $table->unique('public_token', 'sales_vouchers_public_token_unique');
                $table->index(['business_id', 'customer_id', 'invoice_date'], 'sales_vouchers_customer_history_idx');
                $table->index(['business_id', 'status', 'invoice_date'], 'sales_vouchers_status_date_idx');
            });
        }

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                if (!Schema::hasColumn('quotations', 'public_token')) {
                    $table->string('public_token', 80)->nullable();
                }
                if (!Schema::hasColumn('quotations', 'public_share_enabled')) {
                    $table->boolean('public_share_enabled')->default(false);
                }
                if (!Schema::hasColumn('quotations', 'public_token_created_at')) {
                    $table->timestamp('public_token_created_at')->nullable();
                }
            });

            Schema::table('quotations', function (Blueprint $table) {
                $table->unique('public_token', 'quotations_public_token_unique');
            });
        }

        if (!Schema::hasTable('document_share_logs')) {
            Schema::create('document_share_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->unsignedBigInteger('sales_voucher_id')->nullable()->index();
                $table->unsignedBigInteger('quotation_id')->nullable()->index();
                $table->string('document_type', 40)->index();
                $table->string('channel', 30)->default('whatsapp')->index();
                $table->string('recipient', 40)->nullable();
                $table->string('status', 30)->default('initiated')->index();
                $table->unsignedBigInteger('sent_by')->nullable()->index();
                $table->text('message')->nullable();
                $table->string('provider', 60)->default('deep_link');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_share_logs');
    }
};
