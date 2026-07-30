<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches') && !Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->string('code')->nullable()->after('name');
            });

            DB::table('branches')
                ->whereNull('code')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->each(function ($branch) {
                    DB::table('branches')
                        ->where('id', $branch->id)
                        ->update(['code' => 'BR-' . str_pad((string) $branch->id, 3, '0', STR_PAD_LEFT)]);
                });
        }

        if (Schema::hasTable('bank_reconciliations') && !Schema::hasColumn('bank_reconciliations', 'reconciliation_date')) {
            Schema::table('bank_reconciliations', function (Blueprint $table) {
                $table->date('reconciliation_date')->nullable()->after('statement_end_date');
            });

            DB::table('bank_reconciliations')->update([
                'reconciliation_date' => DB::raw('COALESCE(statement_end_date, statement_start_date, DATE(created_at))'),
            ]);
        }

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('audit_logs', 'client_id')) {
                    $table->unsignedBigInteger('client_id')->nullable()->index()->after('id');
                }
                if (!Schema::hasColumn('audit_logs', 'module_name')) {
                    $table->string('module_name', 100)->nullable()->index()->after('client_id');
                }
                if (!Schema::hasColumn('audit_logs', 'action_type')) {
                    $table->string('action_type', 30)->nullable()->index()->after('record_id');
                }
                if (!Schema::hasColumn('audit_logs', 'changed_by_user_id')) {
                    $column = $table->unsignedBigInteger('changed_by_user_id')->nullable()->index();
                    if (Schema::hasColumn('audit_logs', 'changes')) {
                        $column->after('changes');
                    } elseif (Schema::hasColumn('audit_logs', 'actor_id')) {
                        $column->after('actor_id');
                    }
                }
                if (!Schema::hasColumn('audit_logs', 'summary')) {
                    $column = $table->text('summary')->nullable();
                    if (Schema::hasColumn('audit_logs', 'ip_address')) {
                        $column->after('ip_address');
                    }
                }
            });

            $updates = [];
            if (Schema::hasColumn('audit_logs', 'client_id') && Schema::hasColumn('audit_logs', 'tenant_id')) {
                $updates['client_id'] = DB::raw('COALESCE(client_id, tenant_id)');
            }
            if (Schema::hasColumn('audit_logs', 'module_name') && Schema::hasColumn('audit_logs', 'module')) {
                $updates['module_name'] = DB::raw('COALESCE(module_name, module)');
            }
            if (Schema::hasColumn('audit_logs', 'action_type') && Schema::hasColumn('audit_logs', 'action')) {
                $updates['action_type'] = DB::raw('COALESCE(action_type, action)');
            }
            if (Schema::hasColumn('audit_logs', 'changed_by_user_id') && Schema::hasColumn('audit_logs', 'actor_id')) {
                $updates['changed_by_user_id'] = DB::raw('COALESCE(changed_by_user_id, actor_id)');
            }
            if (Schema::hasColumn('audit_logs', 'summary')) {
                $actionColumn = Schema::hasColumn('audit_logs', 'action') ? 'action' : (Schema::hasColumn('audit_logs', 'action_type') ? 'action_type' : null);
                $moduleColumn = Schema::hasColumn('audit_logs', 'module') ? 'module' : (Schema::hasColumn('audit_logs', 'module_name') ? 'module_name' : null);

                if ($actionColumn && $moduleColumn) {
                    $updates['summary'] = DB::raw("COALESCE(summary, CONCAT(COALESCE({$actionColumn}, 'Activity'), ' ', COALESCE({$moduleColumn}, '')))");
                }
            }

            if ($updates) {
                DB::table('audit_logs')->update($updates);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                foreach (['summary', 'changed_by_user_id', 'action_type', 'module_name', 'client_id'] as $column) {
                    if (Schema::hasColumn('audit_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('bank_reconciliations') && Schema::hasColumn('bank_reconciliations', 'reconciliation_date')) {
            Schema::table('bank_reconciliations', function (Blueprint $table) {
                $table->dropColumn('reconciliation_date');
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'code')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }
};
