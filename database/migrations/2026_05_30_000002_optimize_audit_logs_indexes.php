<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'audit_logs';

    private const INDEXES = [
        'audit_logs_module_name_perf_idx' => ['module_name'],
        'audit_logs_action_type_perf_idx' => ['action_type'],
        'audit_logs_changed_by_user_id_perf_idx' => ['changed_by_user_id'],
        'audit_logs_created_at_perf_idx' => ['created_at'],
        'audit_logs_client_created_at_perf_idx' => ['client_id', 'created_at'],
        'audit_logs_client_module_created_at_perf_idx' => ['client_id', 'module_name', 'created_at'],
        'audit_logs_client_action_created_at_perf_idx' => ['client_id', 'action_type', 'created_at'],
        'audit_logs_client_user_created_at_perf_idx' => ['client_id', 'changed_by_user_id', 'created_at'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            foreach (self::INDEXES as $name => $columns) {
                if (!$this->hasIndexForColumns($columns)) {
                    $table->index($columns, $name);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            foreach (array_keys(self::INDEXES) as $name) {
                if ($this->hasIndexNamed($name)) {
                    $table->dropIndex($name);
                }
            }
        });
    }

    private function hasIndexForColumns(array $columns): bool
    {
        foreach ($this->indexes() as $indexColumns) {
            if ($indexColumns === array_values($columns)) {
                return true;
            }
        }

        return false;
    }

    private function hasIndexNamed(string $name): bool
    {
        return array_key_exists($name, $this->indexes());
    }

    private function indexes(): array
    {
        $rows = DB::select('SHOW INDEX FROM '.self::TABLE);
        $indexes = [];

        foreach ($rows as $row) {
            $indexName = $row->Key_name;
            $sequence = (int) $row->Seq_in_index;
            $indexes[$indexName][$sequence] = $row->Column_name;
        }

        foreach ($indexes as $indexName => $columns) {
            ksort($columns);
            $indexes[$indexName] = array_values($columns);
        }

        return $indexes;
    }
};
