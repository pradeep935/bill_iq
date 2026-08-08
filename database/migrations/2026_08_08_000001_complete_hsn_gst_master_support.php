<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->completeHsnMasters();
        $this->completeHsnTaxRates();
        $this->createGstRateSlabs();
        $this->createBusinessHsnUsage();
        $this->completeBusinessHsnUsage();
        $this->completeCategoryHsnMappings();
        $this->createHsnTaxAuditLogs();
        $this->completeHsnTaxAuditLogs();
        $this->createHsnImportBatches();
        $this->completeHsnImportBatches();
        $this->createHsnImportFailures();
        $this->completeHsnImportFailures();
        $this->completeProducts();
        $this->completeSalesItems();
    }

    public function down(): void
    {
        // Non-destructive preservation migration. Existing production data is intentionally retained.
    }

    private function completeHsnMasters(): void
    {
        if (!Schema::hasTable('hsn_masters')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE hsn_masters MODIFY description TEXT NOT NULL');
            DB::statement('ALTER TABLE hsn_masters MODIFY gst_rate DECIMAL(5,2) NULL');
            DB::statement('ALTER TABLE hsn_masters MODIFY cess_rate DECIMAL(5,2) NULL');
        }

        Schema::table('hsn_masters', function (Blueprint $table) {
            if (!Schema::hasColumn('hsn_masters', 'search_keywords')) {
                $table->text('search_keywords')->nullable()->after('description');
            }

            if (!Schema::hasColumn('hsn_masters', 'classification_verified')) {
                $table->boolean('classification_verified')->default(false)->after('taxability')->index();
            }

            if (!Schema::hasColumn('hsn_masters', 'classification_verified_at')) {
                $table->timestamp('classification_verified_at')->nullable()->after('classification_verified');
            }

            if (!Schema::hasColumn('hsn_masters', 'classification_verified_by')) {
                $table->unsignedBigInteger('classification_verified_by')->nullable()->after('classification_verified_at');
            }

            if (!Schema::hasColumn('hsn_masters', 'rate_verified')) {
                $table->boolean('rate_verified')->default(false)->after('classification_verified')->index();
            }

            if (!Schema::hasColumn('hsn_masters', 'section_name')) {
                $table->string('section_name')->nullable()->after('chapter_code');
            }

            if (!Schema::hasColumn('hsn_masters', 'heading_code')) {
                $table->string('heading_code', 12)->nullable()->after('section_name')->index();
            }

            if (!Schema::hasColumn('hsn_masters', 'heading_description')) {
                $table->string('heading_description')->nullable()->after('heading_code');
            }

            if (!Schema::hasColumn('hsn_masters', 'group_code')) {
                $table->string('group_code', 12)->nullable()->after('heading_description')->index();
            }

            if (!Schema::hasColumn('hsn_masters', 'group_description')) {
                $table->string('group_description')->nullable()->after('group_code');
            }

            if (!Schema::hasColumn('hsn_masters', 'source_dataset')) {
                $table->string('source_dataset')->nullable()->after('source_reference');
            }
        });

        $this->addIndexIfMissing('hsn_masters', ['hsn_code', 'effective_from'], 'hsn_masters_code_effective_idx');
    }

    private function completeHsnTaxRates(): void
    {
        if (!Schema::hasTable('hsn_tax_rates')) {
            return;
        }

        Schema::table('hsn_tax_rates', function (Blueprint $table) {
            if (!Schema::hasColumn('hsn_tax_rates', 'rule_name')) {
                $table->string('rule_name')->nullable()->after('hsn_id');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'rule_description')) {
                $table->text('rule_description')->nullable()->after('rule_name');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'condition_text')) {
                $table->text('condition_text')->nullable()->after('rule_description');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'cgst_rate')) {
                $table->decimal('cgst_rate', 5, 2)->default(0)->after('gst_rate');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'sgst_rate')) {
                $table->decimal('sgst_rate', 5, 2)->default(0)->after('cgst_rate');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'igst_rate')) {
                $table->decimal('igst_rate', 5, 2)->default(0)->after('sgst_rate');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'rate_verified')) {
                $table->boolean('rate_verified')->default(false)->after('taxability')->index();
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'cess_amount')) {
                $table->decimal('cess_amount', 15, 2)->nullable()->after('cess_rate');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'cess_basis')) {
                $table->string('cess_basis', 40)->nullable()->after('cess_amount');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'itc_condition')) {
                $table->text('itc_condition')->nullable()->after('cess_basis');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'reverse_charge_applicable')) {
                $table->boolean('reverse_charge_applicable')->default(false)->after('itc_condition');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'notification_date')) {
                $table->date('notification_date')->nullable()->after('notification_number');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'verification_status')) {
                $table->string('verification_status', 30)->default('unverified')->after('source_reference')->index();
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verification_status');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'verified_by')) {
                $table->unsignedBigInteger('verified_by')->nullable()->after('verified_at');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('status');
            }

            if (!Schema::hasColumn('hsn_tax_rates', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE hsn_tax_rates MODIFY gst_rate DECIMAL(5,2) NULL');
            DB::statement('ALTER TABLE hsn_tax_rates MODIFY cgst_rate DECIMAL(5,2) NULL');
            DB::statement('ALTER TABLE hsn_tax_rates MODIFY sgst_rate DECIMAL(5,2) NULL');
            DB::statement('ALTER TABLE hsn_tax_rates MODIFY igst_rate DECIMAL(5,2) NULL');
        }
    }

    private function createGstRateSlabs(): void
    {
        if (Schema::hasTable('gst_rate_slabs')) {
            Schema::table('gst_rate_slabs', function (Blueprint $table) {
                if (!Schema::hasColumn('gst_rate_slabs', 'label')) {
                    $table->string('label', 40)->nullable()->after('rate');
                }

                if (!Schema::hasColumn('gst_rate_slabs', 'is_common')) {
                    $table->boolean('is_common')->default(false)->after('label')->index();
                }

                if (!Schema::hasColumn('gst_rate_slabs', 'selectable')) {
                    $table->boolean('selectable')->default(true)->after('is_common')->index();
                }

                if (!Schema::hasColumn('gst_rate_slabs', 'status')) {
                    $table->string('status', 20)->default('active')->index()->after('selectable');
                }

                if (!Schema::hasColumn('gst_rate_slabs', 'sort_order')) {
                    $table->unsignedSmallInteger('sort_order')->default(0)->after('status');
                }

                if (!Schema::hasColumn('gst_rate_slabs', 'notes')) {
                    $table->string('notes', 500)->nullable()->after('sort_order');
                }
            });

            $this->cleanupGstSlabs();
            return;
        }

        Schema::create('gst_rate_slabs', function (Blueprint $table) {
            $table->id();
            $table->decimal('rate', 5, 2)->unique();
            $table->string('label', 40);
            $table->boolean('is_common')->default(false)->index();
            $table->boolean('selectable')->default(true)->index();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        $this->cleanupGstSlabs();
    }

    private function createBusinessHsnUsage(): void
    {
        if (Schema::hasTable('business_hsn_usage')) {
            return;
        }

        Schema::create('business_hsn_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->foreignId('hsn_id')->constrained('hsn_masters')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'hsn_id', 'product_id'], 'business_hsn_usage_unique');
            $table->index(['business_id', 'last_used_at']);
        });
    }

    private function completeCategoryHsnMappings(): void
    {
        if (!Schema::hasTable('category_hsn_mappings')) {
            return;
        }

        Schema::table('category_hsn_mappings', function (Blueprint $table) {
            if (!Schema::hasColumn('category_hsn_mappings', 'business_id')) {
                $table->unsignedBigInteger('business_id')->nullable()->index()->after('id');
            }

            if (!Schema::hasColumn('category_hsn_mappings', 'keyword')) {
                $table->string('keyword')->nullable()->after('hsn_id')->index();
            }

            if (!Schema::hasColumn('category_hsn_mappings', 'priority')) {
                $table->unsignedSmallInteger('priority')->default(100)->after('keyword')->index();
            }

            if (!Schema::hasColumn('category_hsn_mappings', 'status')) {
                $table->string('status', 20)->default('active')->after('priority')->index();
            }
        });
    }

    private function completeBusinessHsnUsage(): void
    {
        if (!Schema::hasTable('business_hsn_usage')) {
            return;
        }

        Schema::table('business_hsn_usage', function (Blueprint $table) {
            if (!Schema::hasColumn('business_hsn_usage', 'business_id')) {
                $table->unsignedBigInteger('business_id')->nullable()->index()->after('id');
            }

            if (!Schema::hasColumn('business_hsn_usage', 'hsn_id')) {
                $table->unsignedBigInteger('hsn_id')->nullable()->index()->after('business_id');
            }

            if (!Schema::hasColumn('business_hsn_usage', 'product_id')) {
                $table->unsignedBigInteger('product_id')->nullable()->after('hsn_id');
            }

            if (!Schema::hasColumn('business_hsn_usage', 'usage_count')) {
                $table->unsignedInteger('usage_count')->default(0)->after('hsn_id');
            }

            if (!Schema::hasColumn('business_hsn_usage', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('usage_count');
            }

            if (!Schema::hasColumn('business_hsn_usage', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (!Schema::hasColumn('business_hsn_usage', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn('business_hsn_usage', 'use_count') && Schema::hasColumn('business_hsn_usage', 'usage_count')) {
            DB::table('business_hsn_usage')
                ->where(function ($query) {
                    $query->whereNull('usage_count')->orWhere('usage_count', 0);
                })
                ->update(['usage_count' => DB::raw('COALESCE(use_count, 0)')]);
        }
    }

    private function createHsnTaxAuditLogs(): void
    {
        if (Schema::hasTable('hsn_tax_audit_logs')) {
            return;
        }

        Schema::create('hsn_tax_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->foreignId('hsn_id')->nullable()->constrained('hsn_masters')->nullOnDelete();
            $table->foreignId('hsn_tax_rate_id')->nullable()->constrained('hsn_tax_rates')->nullOnDelete();
            $table->string('action', 40)->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('performed_at')->useCurrent();
            $table->timestamps();
        });
    }

    private function completeHsnTaxAuditLogs(): void
    {
        if (!Schema::hasTable('hsn_tax_audit_logs')) {
            return;
        }

        Schema::table('hsn_tax_audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('hsn_tax_audit_logs', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('business_id')->index();
            }

            if (!Schema::hasColumn('hsn_tax_audit_logs', 'auditable_type')) {
                $table->string('auditable_type')->nullable()->after('hsn_tax_rate_id')->index();
            }

            if (!Schema::hasColumn('hsn_tax_audit_logs', 'auditable_id')) {
                $table->unsignedBigInteger('auditable_id')->nullable()->after('auditable_type')->index();
            }

            if (!Schema::hasColumn('hsn_tax_audit_logs', 'event')) {
                $table->string('event', 80)->nullable()->after('auditable_id')->index();
            }

            if (!Schema::hasColumn('hsn_tax_audit_logs', 'reason')) {
                $table->text('reason')->nullable()->after('new_values');
            }
        });
    }

    private function createHsnImportBatches(): void
    {
        if (Schema::hasTable('hsn_import_batches')) {
            return;
        }

        Schema::create('hsn_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable()->index();
            $table->string('source_name');
            $table->string('source_file')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    private function completeHsnImportBatches(): void
    {
        if (!Schema::hasTable('hsn_import_batches')) {
            return;
        }

        Schema::table('hsn_import_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('hsn_import_batches', 'business_id')) {
                $table->unsignedBigInteger('business_id')->nullable()->index()->after('id');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'source_name')) {
                $table->string('source_name')->nullable()->after('business_id');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'source_file')) {
                $table->string('source_file')->nullable()->after('source_name');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'status')) {
                $table->string('status', 30)->default('pending')->index()->after('source_file');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'total_rows')) {
                $table->unsignedInteger('total_rows')->default(0)->after('status');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'imported_rows')) {
                $table->unsignedInteger('imported_rows')->default(0)->after('total_rows');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'failed_rows')) {
                $table->unsignedInteger('failed_rows')->default(0)->after('imported_rows');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('failed_rows');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('finished_at');
            }

            if (!Schema::hasColumn('hsn_import_batches', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (!Schema::hasColumn('hsn_import_batches', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function createHsnImportFailures(): void
    {
        if (Schema::hasTable('hsn_import_failures')) {
            return;
        }

        Schema::create('hsn_import_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hsn_import_batch_id')->constrained('hsn_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->json('row_data')->nullable();
            $table->text('error_message');
            $table->timestamps();
        });
    }

    private function completeHsnImportFailures(): void
    {
        if (!Schema::hasTable('hsn_import_failures')) {
            return;
        }

        Schema::table('hsn_import_failures', function (Blueprint $table) {
            if (!Schema::hasColumn('hsn_import_failures', 'hsn_import_batch_id')) {
                $table->unsignedBigInteger('hsn_import_batch_id')->nullable()->index()->after('id');
            }

            if (!Schema::hasColumn('hsn_import_failures', 'row_number')) {
                $table->unsignedInteger('row_number')->nullable()->after('hsn_import_batch_id');
            }

            if (!Schema::hasColumn('hsn_import_failures', 'row_data')) {
                $table->json('row_data')->nullable()->after('row_number');
            }

            if (!Schema::hasColumn('hsn_import_failures', 'error_message')) {
                $table->text('error_message')->nullable()->after('row_data');
            }

            if (!Schema::hasColumn('hsn_import_failures', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (!Schema::hasColumn('hsn_import_failures', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    private function completeProducts(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'hsn_tax_rate_id')) {
                $table->unsignedBigInteger('hsn_tax_rate_id')->nullable()->after('hsn_master_id')->index();
            }

            if (!Schema::hasColumn('products', 'tax_source')) {
                $table->string('tax_source', 40)->nullable()->after('taxability')->index();
            }

            if (!Schema::hasColumn('products', 'tax_confirmed_by')) {
                $table->unsignedBigInteger('tax_confirmed_by')->nullable()->after('tax_source');
            }

            if (!Schema::hasColumn('products', 'tax_confirmed_at')) {
                $table->timestamp('tax_confirmed_at')->nullable()->after('tax_confirmed_by');
            }

            if (!Schema::hasColumn('products', 'tax_override_reason')) {
                $table->text('tax_override_reason')->nullable()->after('tax_confirmed_at');
            }

            if (!Schema::hasColumn('products', 'tax_override_reference')) {
                $table->string('tax_override_reference')->nullable()->after('tax_override_reason');
            }
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE products MODIFY gst_rate DECIMAL(5,2) NULL');
            DB::statement('ALTER TABLE products MODIFY cess_rate DECIMAL(5,2) NULL');
        }
    }

    private function completeSalesItems(): void
    {
        if (!Schema::hasTable('sales_items')) {
            return;
        }

        Schema::table('sales_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_items', 'hsn_description_snapshot')) {
                $table->text('hsn_description_snapshot')->nullable()->after('hsn_code_type_snapshot');
            }

            if (!Schema::hasColumn('sales_items', 'hsn_tax_rate_id')) {
                $table->unsignedBigInteger('hsn_tax_rate_id')->nullable()->after('hsn_description_snapshot')->index();
            }

            if (!Schema::hasColumn('sales_items', 'tax_source')) {
                $table->string('tax_source', 40)->nullable()->after('taxability_snapshot')->index();
            }

            if (!Schema::hasColumn('sales_items', 'notification_number')) {
                $table->string('notification_number')->nullable()->after('tax_source');
            }

            if (!Schema::hasColumn('sales_items', 'tax_rule_description')) {
                $table->text('tax_rule_description')->nullable()->after('notification_number');
            }
        });
    }

    private function cleanupGstSlabs(): void
    {
        if (!Schema::hasTable('gst_rate_slabs') || !Schema::hasColumn('gst_rate_slabs', 'rate')) {
            return;
        }

        $now = now();
        $active = $this->statusValue('gst_rate_slabs', 'active');
        $inactive = $this->statusValue('gst_rate_slabs', 'inactive');
        $slabs = [
            '0.00' => ['label' => '0%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 10, 'notes' => 'Common selectable rate. Taxability still distinguishes zero-rated, nil-rated, exempt and non-GST.'],
            '0.10' => ['label' => '0.1%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 20, 'notes' => 'Special/rare rate. Not shown as a common Product Master default.'],
            '0.25' => ['label' => '0.25%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 30, 'notes' => 'Special/rare rate. Not shown as a common Product Master default.'],
            '1.00' => ['label' => '1%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 40, 'notes' => 'Special rate/scheme. Not shown as a common Product Master default.'],
            '1.50' => ['label' => '1.5%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 50, 'notes' => 'Special/rare rate. Not shown as a common Product Master default.'],
            '3.00' => ['label' => '3%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 60, 'notes' => 'Special rate commonly used for notified precious goods.'],
            '5.00' => ['label' => '5%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 70, 'notes' => 'Common selectable rate.'],
            '6.00' => ['label' => '6%', 'is_common' => false, 'selectable' => false, 'status' => $inactive, 'sort_order' => 80, 'notes' => 'Retained for historical/reference safety; not selectable as a normal product GST slab.'],
            '7.50' => ['label' => '7.5%', 'is_common' => false, 'selectable' => false, 'status' => $inactive, 'sort_order' => 90, 'notes' => 'Retained for historical/reference safety; not selectable as a normal product GST slab.'],
            '12.00' => ['label' => '12%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 100, 'notes' => 'Common selectable rate.'],
            '18.00' => ['label' => '18%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 110, 'notes' => 'Common selectable rate.'],
            '28.00' => ['label' => '28%', 'is_common' => true, 'selectable' => true, 'status' => $active, 'sort_order' => 120, 'notes' => 'Common selectable high rate.'],
            '40.00' => ['label' => '40%', 'is_common' => false, 'selectable' => true, 'status' => $active, 'sort_order' => 130, 'notes' => 'Special high rate where specifically applicable.'],
        ];

        foreach ($slabs as $rate => $payload) {
            $exists = DB::table('gst_rate_slabs')->where('rate', $rate)->exists();
            $payload['updated_at'] = $now;

            if (!$exists) {
                $payload['rate'] = $rate;
                $payload['created_at'] = $now;
                DB::table('gst_rate_slabs')->insert($payload);
                continue;
            }

            DB::table('gst_rate_slabs')->where('rate', $rate)->update($payload);
        }
    }

    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name) {
            $table->index($columns, $name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return collect(DB::select("SHOW INDEX FROM {$table}"))
            ->contains(fn ($index) => ($index->Key_name ?? null) === $name);
    }

    private function statusValue(string $table, string $status)
    {
        if (DB::connection()->getDriverName() !== 'mysql' || !Schema::hasColumn($table, 'status')) {
            return $status;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM {$table} WHERE Field = 'status'");
        $type = strtolower((string) ($column->Type ?? ''));

        if (str_contains($type, 'tinyint') || str_contains($type, 'int') || str_contains($type, 'bool')) {
            return $status === 'active' ? 1 : 0;
        }

        return $status;
    }
};
