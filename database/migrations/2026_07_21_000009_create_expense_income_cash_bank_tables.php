<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->upgradeExpenseCategories();
        $this->cashBankAccountFields();
        $this->expenseIncomeSettings();
        $this->incomeCategories();
        $this->expenseVouchers();
        $this->otherIncomeVouchers();
        $this->recurringExpenses();
        $this->pettyCash();
        $this->bankStatements();
        $this->seedCategories();
        $this->seedPermissions();
    }

    private function upgradeExpenseCategories(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('expense_categories', 'parent_id')) $table->foreignId('parent_id')->nullable()->after('business_id')->constrained('expense_categories')->nullOnDelete();
            if (!Schema::hasColumn('expense_categories', 'category_code')) $table->string('category_code', 50)->nullable()->after('parent_id');
            if (!Schema::hasColumn('expense_categories', 'category_name')) $table->string('category_name')->nullable()->after('category_code');
            if (!Schema::hasColumn('expense_categories', 'expense_account_id')) $table->foreignId('expense_account_id')->nullable()->after('category_name')->constrained('accounts')->restrictOnDelete();
            if (!Schema::hasColumn('expense_categories', 'description')) $table->text('description')->nullable()->after('expense_account_id');
            if (!Schema::hasColumn('expense_categories', 'is_system')) $table->boolean('is_system')->default(false)->after('description');
            if (!Schema::hasColumn('expense_categories', 'created_by')) $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            if (!Schema::hasColumn('expense_categories', 'updated_by')) $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            if (!Schema::hasColumn('expense_categories', 'deleted_at')) $table->softDeletes();
        });

        DB::table('expense_categories')->whereNull('category_name')->update(['category_name' => DB::raw('COALESCE(name, "Expense Category")')]);
        DB::table('expense_categories')->whereNull('category_code')->update(['category_code' => DB::raw('COALESCE(CONCAT("EXP-", id), "EXP")')]);
        DB::table('expense_categories')->whereNull('expense_account_id')->update(['expense_account_id' => DB::raw('account_id')]);
    }

    private function cashBankAccountFields(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'branch_id')) $table->foreignId('branch_id')->nullable()->after('business_id')->constrained('branches')->nullOnDelete();
            if (!Schema::hasColumn('accounts', 'bank_name')) $table->string('bank_name')->nullable()->after('is_reconciliation_enabled');
            if (!Schema::hasColumn('accounts', 'account_holder_name')) $table->string('account_holder_name')->nullable()->after('bank_name');
            if (!Schema::hasColumn('accounts', 'account_number')) $table->string('account_number')->nullable()->after('account_holder_name');
            if (!Schema::hasColumn('accounts', 'bank_account_type')) $table->string('bank_account_type', 40)->nullable()->after('account_number');
            if (!Schema::hasColumn('accounts', 'ifsc_code')) $table->string('ifsc_code', 30)->nullable()->after('bank_account_type');
            if (!Schema::hasColumn('accounts', 'bank_branch_name')) $table->string('bank_branch_name')->nullable()->after('ifsc_code');
            if (!Schema::hasColumn('accounts', 'upi_id')) $table->string('upi_id')->nullable()->after('bank_branch_name');
            if (!Schema::hasColumn('accounts', 'swift_code')) $table->string('swift_code', 40)->nullable()->after('upi_id');
        });
    }

    private function expenseIncomeSettings(): void
    {
        Schema::table('business_account_settings', function (Blueprint $table) {
            foreach ([
                'tds_payable_account_id', 'employee_reimbursement_payable_account_id', 'petty_cash_advance_account_id',
                'bank_charges_account_id', 'interest_income_account_id', 'interest_expense_account_id',
                'payment_gateway_charges_account_id', 'payment_gateway_clearing_account_id',
            ] as $column) {
                if (!Schema::hasColumn('business_account_settings', $column)) {
                    $table->foreignId($column)->nullable()->constrained('accounts')->nullOnDelete();
                }
            }
        });
    }

    private function incomeCategories(): void
    {
        if (Schema::hasTable('income_categories')) return;
        Schema::create('income_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('income_categories')->nullOnDelete();
            $table->string('category_code', 50);
            $table->string('category_name');
            $table->foreignId('income_account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('status', 20)->default('active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['business_id', 'category_code']);
            $table->index(['business_id', 'status']);
        });
    }

    private function expenseVouchers(): void
    {
        if (!Schema::hasTable('expense_vouchers')) {
            Schema::create('expense_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('voucher_number', 50);
                $table->date('expense_date');
                $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
                $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
                $table->foreignId('paid_from_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
                $table->string('party_name')->nullable();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->string('invoice_number')->nullable();
                $table->date('invoice_date')->nullable();
                $table->string('payment_mode', 40)->default('cash')->index();
                $table->string('reference_number')->nullable();
                $table->string('tax_type', 30)->default('exclusive');
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->decimal('cgst_amount', 15, 2)->default(0);
                $table->decimal('sgst_amount', 15, 2)->default(0);
                $table->decimal('igst_amount', 15, 2)->default(0);
                $table->decimal('cess_amount', 15, 2)->default(0);
                $table->decimal('non_taxable_amount', 15, 2)->default(0);
                $table->decimal('total_amount', 15, 2);
                $table->boolean('tds_applicable')->default(false);
                $table->unsignedBigInteger('tds_section_id')->nullable()->index();
                $table->decimal('tds_rate', 5, 2)->default(0);
                $table->decimal('tds_amount', 15, 2)->default(0);
                $table->decimal('net_paid_amount', 15, 2);
                $table->string('payment_status', 20)->default('paid')->index();
                $table->string('status', 20)->default('draft')->index();
                $table->text('narration')->nullable();
                $table->foreignId('journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'voucher_number']);
                $table->index(['business_id', 'expense_date']);
                $table->index(['business_id', 'supplier_id', 'invoice_number']);
            });
        }

        if (!Schema::hasTable('expense_items')) {
            Schema::create('expense_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('expense_voucher_id')->constrained('expense_vouchers')->cascadeOnDelete();
                $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
                $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
                $table->string('description');
                $table->string('hsn_sac_code')->nullable();
                $table->decimal('quantity', 15, 3)->default(1);
                $table->decimal('rate', 15, 2);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2);
                $table->decimal('gst_rate', 5, 2)->default(0);
                $table->decimal('cgst_amount', 15, 2)->default(0);
                $table->decimal('sgst_amount', 15, 2)->default(0);
                $table->decimal('igst_amount', 15, 2)->default(0);
                $table->decimal('cess_amount', 15, 2)->default(0);
                $table->decimal('line_total', 15, 2);
                $table->unsignedBigInteger('cost_center_id')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('expense_attachments')) {
            Schema::create('expense_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('expense_voucher_id')->constrained('expense_vouchers')->cascadeOnDelete();
                $table->string('file_name');
                $table->string('original_name');
                $table->string('file_path');
                $table->string('mime_type', 120);
                $table->unsignedBigInteger('file_size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'expense_voucher_id']);
            });
        }
    }

    private function otherIncomeVouchers(): void
    {
        if (Schema::hasTable('other_income_vouchers')) return;
        Schema::create('other_income_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('voucher_number', 50);
            $table->date('income_date');
            $table->foreignId('income_category_id')->constrained('income_categories')->restrictOnDelete();
            $table->foreignId('income_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('received_into_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('party_name')->nullable();
            $table->string('payment_mode', 40)->default('cash')->index();
            $table->string('reference_number')->nullable();
            $table->string('tax_type', 30)->default('exclusive');
            $table->decimal('taxable_amount', 15, 2)->default(0);
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('igst_amount', 15, 2)->default(0);
            $table->decimal('cess_amount', 15, 2)->default(0);
            $table->decimal('non_taxable_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->string('status', 20)->default('draft')->index();
            $table->text('narration')->nullable();
            $table->foreignId('journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'voucher_number']);
            $table->index(['business_id', 'income_date']);
        });
    }

    private function recurringExpenses(): void
    {
        if (Schema::hasTable('recurring_expense_templates')) return;
        Schema::create('recurring_expense_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('template_name');
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('paid_from_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('frequency', 30);
            $table->unsignedInteger('interval_value')->default(1);
            $table->date('start_date');
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('tax_type', 30)->default('exclusive');
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->boolean('auto_create_draft')->default(true);
            $table->boolean('auto_post')->default(false);
            $table->boolean('approval_required')->default(true);
            $table->string('status', 20)->default('active')->index();
            $table->text('narration')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['business_id', 'next_run_date', 'status']);
        });

        Schema::create('recurring_expense_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('recurring_expense_template_id')->constrained('recurring_expense_templates')->cascadeOnDelete();
            $table->date('run_date');
            $table->foreignId('expense_voucher_id')->nullable()->constrained('expense_vouchers')->nullOnDelete();
            $table->string('status', 20)->default('created')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['recurring_expense_template_id', 'run_date']);
        });
    }

    private function pettyCash(): void
    {
        if (Schema::hasTable('petty_cash_advances')) return;
        Schema::create('petty_cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedBigInteger('employee_id')->index();
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('voucher_number', 50);
            $table->date('advance_date');
            $table->decimal('amount', 15, 2);
            $table->decimal('settled_amount', 15, 2)->default(0);
            $table->decimal('balance_amount', 15, 2);
            $table->string('purpose');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['business_id', 'voucher_number']);
        });
    }

    private function bankStatements(): void
    {
        if (!Schema::hasTable('bank_statement_imports')) {
            Schema::create('bank_statement_imports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
                $table->string('file_name');
                $table->date('statement_start_date')->nullable();
                $table->date('statement_end_date')->nullable();
                $table->decimal('opening_balance', 15, 2)->nullable();
                $table->decimal('closing_balance', 15, 2)->nullable();
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('imported_rows')->default(0);
                $table->unsignedInteger('duplicate_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->string('status', 20)->default('draft')->index();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('bank_statement_lines')) {
            Schema::create('bank_statement_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_statement_import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
                $table->date('transaction_date');
                $table->date('value_date')->nullable();
                $table->text('description');
                $table->string('reference_number')->nullable();
                $table->string('cheque_number')->nullable();
                $table->decimal('debit_amount', 15, 2)->default(0);
                $table->decimal('credit_amount', 15, 2)->default(0);
                $table->decimal('running_balance', 15, 2)->nullable();
                $table->string('external_transaction_id')->nullable();
                $table->string('reconciliation_status', 30)->default('unreconciled')->index();
                $table->foreignId('matched_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->timestamp('matched_at')->nullable();
                $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'bank_account_id', 'transaction_date']);
                $table->unique(['business_id', 'bank_account_id', 'transaction_date', 'reference_number', 'external_transaction_id'], 'bank_statement_duplicate_index');
            });
        }

        if (!Schema::hasTable('bank_reconciliations')) {
            Schema::create('bank_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
                $table->date('statement_start_date')->nullable();
                $table->date('statement_end_date')->nullable();
                $table->decimal('statement_closing_balance', 15, 2)->default(0);
                $table->decimal('ledger_closing_balance', 15, 2)->default(0);
                $table->decimal('difference_amount', 15, 2)->default(0);
                $table->string('status', 20)->default('draft')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'bank_account_id']);
            });
        }

        if (!Schema::hasTable('bank_reconciliation_items')) {
            Schema::create('bank_reconciliation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('bank_statement_line_id')->nullable()->constrained('bank_statement_lines')->nullOnDelete();
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->decimal('matched_amount', 15, 2);
                $table->string('match_type', 30)->default('manual');
                $table->string('status', 20)->default('matched')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'status']);
            });
        }
    }

    private function seedCategories(): void
    {
        $expenseNames = ['Rent', 'Electricity', 'Internet', 'Telephone', 'Office Supplies', 'Travel', 'Fuel', 'Repairs and Maintenance', 'Marketing', 'Advertising', 'Salary', 'Professional Fees', 'Software Subscription', 'Bank Charges', 'Courier', 'Insurance', 'Staff Welfare', 'Miscellaneous Expense'];
        $incomeNames = ['Interest Income', 'Commission Income', 'Rental Income', 'Service Charges', 'Delivery Charges', 'Scrap Sales', 'Incentives', 'Cashback', 'Miscellaneous Income'];

        foreach (DB::table('companies')->get(['id']) as $company) {
            $expenseAccount = DB::table('accounts')->where('business_id', $company->id)->where('account_code', 'SHIP-E')->value('id')
                ?: DB::table('accounts')->where('business_id', $company->id)->where('account_type', 'expense')->value('id');
            $incomeAccount = DB::table('accounts')->where('business_id', $company->id)->where('account_code', 'SHIP-I')->value('id')
                ?: DB::table('accounts')->where('business_id', $company->id)->where('account_type', 'income')->value('id');

            foreach ($expenseNames as $index => $name) {
                DB::table('expense_categories')->updateOrInsert(
                    ['business_id' => $company->id, 'category_code' => 'EXP-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                    ['category_name' => $name, 'name' => $name, 'expense_account_id' => $expenseAccount, 'account_id' => $expenseAccount, 'is_system' => true, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]
                );
            }

            foreach ($incomeNames as $index => $name) {
                DB::table('income_categories')->updateOrInsert(
                    ['business_id' => $company->id, 'category_code' => 'INC-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                    ['category_name' => $name, 'income_account_id' => $incomeAccount, 'is_system' => true, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]
                );
            }

            $this->seedAccount($company->id, 'TDS-PAY', 'TDS Payable', 'tax', 'TAX');
            $this->seedAccount($company->id, 'EMP-REIMB', 'Employee Reimbursement Payable', 'supplier', 'AP');
            $this->seedAccount($company->id, 'PETTY-ADV', 'Employee Petty Cash Advance', 'ledger', 'CA');
            $this->seedAccount($company->id, 'BANK-CHG', 'Bank Charges', 'expense', 'EXP');
            $this->seedAccount($company->id, 'INT-INC', 'Interest Income', 'income', 'INC');
            $this->seedAccount($company->id, 'INT-EXP', 'Interest Expense', 'expense', 'EXP');
            $this->seedAccount($company->id, 'PG-CHG', 'Payment Gateway Charges', 'expense', 'EXP');
            $this->seedAccount($company->id, 'PG-CLR', 'Payment Gateway Clearing', 'ledger', 'CA');

            $accountId = fn ($code) => DB::table('accounts')->where('business_id', $company->id)->where('account_code', $code)->value('id');
            DB::table('business_account_settings')->where('business_id', $company->id)->update([
                'tds_payable_account_id' => $accountId('TDS-PAY'),
                'employee_reimbursement_payable_account_id' => $accountId('EMP-REIMB'),
                'petty_cash_advance_account_id' => $accountId('PETTY-ADV'),
                'bank_charges_account_id' => $accountId('BANK-CHG'),
                'interest_income_account_id' => $accountId('INT-INC'),
                'interest_expense_account_id' => $accountId('INT-EXP'),
                'payment_gateway_charges_account_id' => $accountId('PG-CHG'),
                'payment_gateway_clearing_account_id' => $accountId('PG-CLR'),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedAccount(int $businessId, string $code, string $name, string $type, string $groupCode): void
    {
        $groupId = DB::table('account_groups')->where('business_id', $businessId)->where('group_code', $groupCode)->value('id');
        DB::table('accounts')->updateOrInsert(
            ['business_id' => $businessId, 'account_code' => $code],
            ['account_group_id' => $groupId, 'account_name' => $name, 'name' => $name, 'account_type' => $type, 'code' => $code, 'is_system' => true, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) return;
        $names = ['view expense categories','manage expense categories','view income categories','manage income categories','view expenses','create expense','edit draft expense','submit expense','approve expense','reject expense','post expense','reverse expense','view expense attachments','upload expense attachments','delete expense attachments','view other income','create other income','approve other income','reverse other income','manage recurring expenses','auto-post recurring expense','view petty cash','issue petty cash','settle petty cash','view cash accounts','manage cash accounts','view bank accounts','manage bank accounts','import bank statement','reconcile bank','unmatch bank transaction','create transaction from bank statement','view expense reports','view income reports','view bank reconciliation report','view sensitive bank details'];
        foreach ($names as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['module' => 'accounting', 'description' => ucfirst($name), 'updated_at' => now(), 'created_at' => now()]);
        }
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        foreach ([1, 2] as $roleId) foreach ($ids as $id) DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['updated_at' => now(), 'created_at' => now()]);
    }

    public function down(): void
    {
        // Financial documents are retained intentionally.
    }
};
