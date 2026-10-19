<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tally_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('name')->default('Tally connection');
            $table->string('location', 20); // local|cloud
            $table->string('cloud_type', 30)->nullable(); // api|hosted_windows
            $table->string('provider', 80)->nullable();
            $table->string('endpoint_url')->nullable();
            $table->text('credentials')->nullable();
            $table->string('pairing_token_hash', 64)->nullable()->index();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->string('tally_version')->nullable();
            $table->string('tally_company_name')->nullable();
            $table->string('tally_company_guid')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('settings_json')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tally_sync_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->foreignId('tally_connection_id')->constrained('tally_connections')->cascadeOnDelete();
            $table->string('connector_request_id', 64)->nullable();
            $table->string('status', 30)->default('received')->index();
            $table->string('mode', 20)->default('incremental');
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('cursor')->nullable();
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_ready')->default(0);
            $table->unsignedInteger('records_imported')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->json('summary_json')->nullable();
            $table->json('error_json')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'connector_request_id'], 'tally_batch_request_unique');
        });

        Schema::create('tally_source_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->foreignId('tally_sync_batch_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 50)->index();
            $table->string('source_key', 191);
            $table->string('source_name')->nullable();
            $table->string('source_group')->nullable();
            $table->date('source_date')->nullable();
            $table->string('source_number')->nullable();
            $table->decimal('debit_amount', 15, 2)->default(0);
            $table->decimal('credit_amount', 15, 2)->default(0);
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('status', 30)->default('unmatched')->index();
            $table->string('fingerprint', 64)->index();
            $table->json('payload_json')->nullable();
            $table->json('validation_json')->nullable();
            $table->string('imported_type')->nullable();
            $table->unsignedBigInteger('imported_id')->nullable();
            $table->text('import_error')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'source_type', 'source_key'], 'tally_source_record_unique');
        });

        Schema::create('tally_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('source_type', 50);
            $table->string('source_key', 191);
            $table->string('source_name')->nullable();
            $table->string('target_type', 50);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 30)->default('suggested')->index();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->json('evidence_json')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'source_type', 'source_key'], 'tally_mapping_unique');
        });

        Schema::create('tally_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->foreignId('tally_sync_batch_id')->constrained()->cascadeOnDelete();
            $table->string('category', 60)->index();
            $table->string('status', 20)->default('unavailable');
            $table->decimal('tally_amount', 15, 2)->nullable();
            $table->decimal('billiq_amount', 15, 2)->nullable();
            $table->decimal('difference_amount', 15, 2)->nullable();
            $table->json('details_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tally_reconciliation_items');
        Schema::dropIfExists('tally_mappings');
        Schema::dropIfExists('tally_source_records');
        Schema::dropIfExists('tally_sync_batches');
        Schema::dropIfExists('tally_connections');
    }
};
