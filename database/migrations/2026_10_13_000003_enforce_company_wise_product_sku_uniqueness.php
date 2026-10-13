<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'products';

    private string $globalSkuIndex = 'products_sku_unique';

    private string $businessSkuIndex = 'products_business_id_sku_unique';

    public function up(): void
    {
        if (!Schema::hasTable($this->table) || !Schema::hasColumn($this->table, 'sku') || !Schema::hasColumn($this->table, 'business_id')) {
            return;
        }

        $this->assertNoDuplicateBusinessSkus();

        Schema::table($this->table, function (Blueprint $table) {
            if ($this->hasIndex($this->globalSkuIndex)) {
                $table->dropUnique($this->globalSkuIndex);
            }

            if (!$this->hasIndex($this->businessSkuIndex)) {
                $table->unique(['business_id', 'sku'], $this->businessSkuIndex);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable($this->table) || !Schema::hasColumn($this->table, 'sku')) {
            return;
        }

        $this->assertNoDuplicateGlobalSkus();

        Schema::table($this->table, function (Blueprint $table) {
            if ($this->hasIndex($this->businessSkuIndex)) {
                $table->dropUnique($this->businessSkuIndex);
            }

            if (!$this->hasIndex($this->globalSkuIndex)) {
                $table->unique('sku', $this->globalSkuIndex);
            }
        });
    }

    private function assertNoDuplicateBusinessSkus(): void
    {
        $duplicate = DB::table($this->table)
            ->select('business_id', 'sku', DB::raw('COUNT(*) as aggregate_count'))
            ->whereNotNull('business_id')
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->groupBy('business_id', 'sku')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException("Cannot add {$this->businessSkuIndex}: duplicate SKU [{$duplicate->sku}] exists for business [{$duplicate->business_id}].");
        }
    }

    private function assertNoDuplicateGlobalSkus(): void
    {
        $duplicate = DB::table($this->table)
            ->select('sku', DB::raw('COUNT(*) as aggregate_count'))
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->groupBy('sku')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate) {
            throw new RuntimeException("Cannot restore {$this->globalSkuIndex}: duplicate SKU [{$duplicate->sku}] exists across products.");
        }
    }

    private function hasIndex(string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$this->table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $this->table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
