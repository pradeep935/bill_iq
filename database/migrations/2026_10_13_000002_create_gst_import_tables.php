<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('gst_import_batches')) {
            Schema::create('gst_import_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('source_type', 40)->index();
                $table->string('status', 30)->default('uploaded')->index();
                $table->string('original_filename');
                $table->string('storage_path');
                $table->string('file_hash', 64);
                $table->string('source_gstin', 15)->nullable()->index();
                $table->string('return_period', 20)->nullable()->index();
                $table->string('detected_legal_name')->nullable();
                $table->json('totals_json')->nullable();
                $table->json('validation_json')->nullable();
                $table->json('summary_json')->nullable();
                $table->unsignedBigInteger('uploaded_by')->nullable()->index();
                $table->unsignedBigInteger('approved_by')->nullable()->index();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('committed_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'file_hash'], 'gst_import_batches_business_hash_unique');
            });
        }

        if (!Schema::hasTable('gst_import_rows')) {
            Schema::create('gst_import_rows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gst_import_batch_id')->index();
                $table->unsignedInteger('row_number')->default(0);
                $table->string('section', 80)->index();
                $table->string('source_key')->nullable()->index();
                $table->string('counterparty_gstin', 15)->nullable()->index();
                $table->string('document_number')->nullable()->index();
                $table->date('document_date')->nullable();
                $table->string('hsn_code', 20)->nullable()->index();
                $table->decimal('taxable_value', 15, 2)->default(0);
                $table->decimal('cgst_amount', 15, 2)->default(0);
                $table->decimal('sgst_amount', 15, 2)->default(0);
                $table->decimal('igst_amount', 15, 2)->default(0);
                $table->decimal('cess_amount', 15, 2)->default(0);
                $table->decimal('total_value', 15, 2)->default(0);
                $table->string('severity', 20)->default('info')->index();
                $table->json('messages_json')->nullable();
                $table->json('payload_json')->nullable();
                $table->string('duplicate_key', 191)->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('gst_reconciliation_items')) {
            Schema::create('gst_reconciliation_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gst_import_batch_id')->index();
                $table->string('category', 80)->index();
                $table->string('status', 30)->default('open')->index();
                $table->decimal('expected_amount', 15, 2)->default(0);
                $table->decimal('actual_amount', 15, 2)->default(0);
                $table->decimal('difference_amount', 15, 2)->default(0);
                $table->json('details_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_reconciliation_items');
        Schema::dropIfExists('gst_import_rows');
        Schema::dropIfExists('gst_import_batches');
    }
};
