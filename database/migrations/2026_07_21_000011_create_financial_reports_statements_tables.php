<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->classifications();
        $this->reportTables();
        $this->indexes();
        $this->seedClassifications();
        $this->seedPermissions();
    }

    private function classifications(): void
    {
        Schema::table('account_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('account_groups', 'financial_statement_type')) $table->string('financial_statement_type', 30)->nullable()->after('nature')->index();
            if (!Schema::hasColumn('account_groups', 'financial_statement_section')) $table->string('financial_statement_section', 80)->nullable()->after('financial_statement_type')->index();
            if (!Schema::hasColumn('account_groups', 'cash_flow_category')) $table->string('cash_flow_category', 30)->nullable()->after('financial_statement_section')->index();
            if (!Schema::hasColumn('account_groups', 'report_order')) $table->unsignedInteger('report_order')->default(100)->after('cash_flow_category');
            if (!Schema::hasColumn('account_groups', 'normal_balance')) $table->string('normal_balance', 10)->nullable()->after('report_order');
            if (!Schema::hasColumn('account_groups', 'is_control_group')) $table->boolean('is_control_group')->default(false)->after('normal_balance');
        });

        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'financial_statement_section')) $table->string('financial_statement_section', 80)->nullable()->after('account_type')->index();
            if (!Schema::hasColumn('accounts', 'cash_flow_category')) $table->string('cash_flow_category', 30)->nullable()->after('financial_statement_section')->index();
            if (!Schema::hasColumn('accounts', 'report_order')) $table->unsignedInteger('report_order')->default(100)->after('cash_flow_category');
            if (!Schema::hasColumn('accounts', 'is_control_account')) $table->boolean('is_control_account')->default(false)->after('report_order');
        });
    }

    private function reportTables(): void
    {
        if (!Schema::hasTable('financial_year_closures')) {
            Schema::create('financial_year_closures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('financial_year', 20);
                $table->date('closing_date');
                $table->string('status', 30)->default('draft')->index();
                $table->foreignId('closing_journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
                $table->decimal('profit_loss_amount', 15, 2)->nullable();
                $table->foreignId('retained_earnings_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('next_year_opening_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
                $table->json('checklist_json')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reopened_at')->nullable();
                $table->text('reopen_reason')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'financial_year']);
            });
        }

        if (!Schema::hasTable('financial_report_snapshots')) {
            Schema::create('financial_report_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('report_type', 40);
                $table->string('financial_year', 20)->nullable();
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->unsignedInteger('version_number')->default(1);
                $table->json('snapshot_json');
                $table->string('status', 20)->default('draft')->index();
                $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'report_type', 'financial_year'], 'financial_snapshot_report_index');
            });
        }

        if (!Schema::hasTable('financial_report_exceptions')) {
            Schema::create('financial_report_exceptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('exception_type', 80)->index();
                $table->string('severity', 20)->default('warning')->index();
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('source_number')->nullable();
                $table->text('message');
                $table->text('suggested_action')->nullable();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->string('resolution_status', 30)->default('open')->index();
                $table->json('audit_json')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'source_type', 'source_id'], 'financial_exception_source_index');
            });
        }
    }

    private function indexes(): void
    {
        Schema::table('journal_vouchers', function (Blueprint $table) {
            if (!$this->indexExists('journal_vouchers', 'journal_vouchers_report_index')) $table->index(['business_id', 'voucher_date', 'status'], 'journal_vouchers_report_index');
            if (!$this->indexExists('journal_vouchers', 'journal_vouchers_type_report_index')) $table->index(['business_id', 'voucher_type', 'voucher_date'], 'journal_vouchers_type_report_index');
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            if (!$this->indexExists('journal_entries', 'journal_entries_account_branch_index')) $table->index(['business_id', 'account_id', 'branch_id'], 'journal_entries_account_branch_index');
            if (!$this->indexExists('journal_entries', 'journal_entries_reference_report_index')) $table->index(['reference_type', 'reference_id'], 'journal_entries_reference_report_index');
        });
        Schema::table('accounts', function (Blueprint $table) {
            if (!$this->indexExists('accounts', 'accounts_group_report_index')) $table->index(['business_id', 'account_group_id'], 'accounts_group_report_index');
        });
        Schema::table('ledger_allocations', function (Blueprint $table) {
            if (!$this->indexExists('ledger_allocations', 'ledger_allocations_ref_report_index')) $table->index(['reference_type', 'reference_id'], 'ledger_allocations_ref_report_index');
        });
    }

    private function seedClassifications(): void
    {
        $map = [
            'CA' => ['balance_sheet', 'Current Assets', 'operating', 10, 'debit', false],
            'CASH' => ['balance_sheet', 'Cash and Cash Equivalents', 'cash_equivalent', 11, 'debit', true],
            'BANK' => ['balance_sheet', 'Bank Accounts', 'cash_equivalent', 12, 'debit', true],
            'AR' => ['balance_sheet', 'Accounts Receivable', 'operating', 13, 'debit', true],
            'INV' => ['balance_sheet', 'Inventory', 'operating', 14, 'debit', true],
            'CL' => ['balance_sheet', 'Current Liabilities', 'operating', 50, 'credit', false],
            'AP' => ['balance_sheet', 'Accounts Payable', 'operating', 51, 'credit', true],
            'TAX' => ['balance_sheet', 'Tax Liabilities', 'operating', 52, 'credit', true],
            'CAP' => ['balance_sheet', 'Capital', 'financing', 80, 'credit', true],
            'SALE' => ['profit_and_loss', 'Sales Revenue', 'operating', 100, 'credit', true],
            'PUR' => ['profit_and_loss', 'Cost of Goods Sold', 'operating', 200, 'debit', true],
            'EXP' => ['profit_and_loss', 'Indirect Expenses', 'operating', 300, 'debit', false],
            'INC' => ['profit_and_loss', 'Other Income', 'operating', 400, 'credit', false],
        ];

        foreach ($map as $code => $values) {
            DB::table('account_groups')->where('group_code', $code)->update([
                'financial_statement_type' => $values[0],
                'financial_statement_section' => $values[1],
                'cash_flow_category' => $values[2],
                'report_order' => $values[3],
                'normal_balance' => $values[4],
                'is_control_group' => $values[5],
                'updated_at' => now(),
            ]);
        }

        DB::table('accounts')->join('account_groups', 'account_groups.id', '=', 'accounts.account_group_id')
            ->update([
                'accounts.financial_statement_section' => DB::raw('account_groups.financial_statement_section'),
                'accounts.cash_flow_category' => DB::raw('account_groups.cash_flow_category'),
                'accounts.report_order' => DB::raw('account_groups.report_order'),
                'accounts.is_control_account' => DB::raw('account_groups.is_control_group'),
            ]);
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) return;
        $names = ['view day book','view journal register','view general ledger','view trial balance','view profit and loss','view balance sheet','view cash flow','view receivable reports','view payable reports','view account schedules','view comparative financial reports','view branch financial reports','view consolidated financial reports','view financial dashboard','view financial ratios','export financial reports','print financial reports','view account cost details','manage account classifications','prepare year-end closing','approve year-end closing','close financial year','reopen financial year','create financial report snapshot','approve financial report snapshot','view financial exceptions'];
        foreach ($names as $name) DB::table('permissions')->updateOrInsert(['name' => $name], ['module' => 'financial_reports', 'description' => ucfirst($name), 'created_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        foreach ([1, 2] as $roleId) foreach ($ids as $id) DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();
        return DB::table('information_schema.statistics')->where('table_schema', $database)->where('table_name', $table)->where('index_name', $index)->exists();
    }

    public function down(): void
    {
        // Financial report history and classifications are retained intentionally.
    }
};
