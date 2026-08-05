<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hsn_masters')) {
            Schema::table('hsn_masters', function (Blueprint $table) {
                if (!Schema::hasColumn('hsn_masters', 'business_id')) {
                    $table->unsignedBigInteger('business_id')->nullable()->after('id')->index();
                }

                if (!Schema::hasColumn('hsn_masters', 'code_type')) {
                    $table->string('code_type', 10)->default('HSN')->after('business_id')->index();
                }

                if (!Schema::hasColumn('hsn_masters', 'taxability')) {
                    $table->string('taxability', 20)->default('taxable')->after('cess_rate')->index();
                }
            });

            DB::table('hsn_masters')->whereNull('code_type')->orWhere('code_type', '')->update(['code_type' => 'HSN']);
            DB::table('hsn_masters')->whereNull('taxability')->orWhere('taxability', '')->update(['taxability' => 'taxable']);
            DB::table('hsn_masters')->whereNull('effective_from')->update(['effective_from' => now()->toDateString()]);
            DB::table('hsn_masters')
                ->whereIn('taxability', ['exempt', 'nil_rated', 'non_gst'])
                ->update(['gst_rate' => 0]);

            $this->addIndexIfMissing('hsn_masters', ['business_id', 'code_type', 'hsn_code'], 'hsn_masters_business_type_code_unique', true);
            $this->addIndexIfMissing('hsn_masters', ['business_id', 'code_type', 'status'], 'hsn_masters_business_type_status_idx');
        }

        if (Schema::hasTable('hsn_tax_rates')) {
            Schema::table('hsn_tax_rates', function (Blueprint $table) {
                if (!Schema::hasColumn('hsn_tax_rates', 'taxability')) {
                    $table->string('taxability', 20)->default('taxable')->after('cess_rate')->index();
                }
            });
        }

        if (Schema::hasTable('sales_items')) {
            Schema::table('sales_items', function (Blueprint $table) {
                if (!Schema::hasColumn('sales_items', 'hsn_code_type_snapshot')) {
                    $table->string('hsn_code_type_snapshot', 10)->nullable()->after('hsn_code_snapshot');
                }

                if (!Schema::hasColumn('sales_items', 'taxability_snapshot')) {
                    $table->string('taxability_snapshot', 20)->nullable()->after('hsn_code_type_snapshot');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_items')) {
            Schema::table('sales_items', function (Blueprint $table) {
                if (Schema::hasColumn('sales_items', 'taxability_snapshot')) {
                    $table->dropColumn('taxability_snapshot');
                }

                if (Schema::hasColumn('sales_items', 'hsn_code_type_snapshot')) {
                    $table->dropColumn('hsn_code_type_snapshot');
                }
            });
        }
    }

    private function addIndexIfMissing(string $table, array $columns, string $name, bool $unique = false): void
    {
        $indexExists = collect(DB::select("SHOW INDEX FROM {$table}"))
            ->contains(fn ($index) => ($index->Key_name ?? null) === $name);

        if ($indexExists) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name, $unique) {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }
};
