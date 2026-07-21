<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->accountGroups();
        $this->upgradeAccounts();
        $this->accountingTables();
        $this->seedSystemAccounts();
        $this->seedPermissions();
    }

    private function accountGroups(): void
    {
        if (!Schema::hasTable('account_groups')) {
            Schema::create('account_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained('companies')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('account_groups')->nullOnDelete();
                $table->string('group_code', 50);
                $table->string('group_name');
                $table->string('group_type', 30);
                $table->string('nature', 10);
                $table->boolean('is_system')->default(false);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();

                $table->unique(['business_id', 'group_code']);
            });
        }
    }

    private function upgradeAccounts(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('accounts', 'account_group_id')) {
                $table->foreignId('account_group_id')->nullable()->after('business_id')->constrained('account_groups')->nullOnDelete();
            }
            if (!Schema::hasColumn('accounts', 'parent_account_id')) {
                $table->foreignId('parent_account_id')->nullable()->after('account_group_id')->constrained('accounts')->nullOnDelete();
            }
            if (!Schema::hasColumn('accounts', 'account_code')) {
                $table->string('account_code', 50)->nullable()->after('parent_account_id');
            }
            if (!Schema::hasColumn('accounts', 'account_name')) {
                $table->string('account_name')->nullable()->after('account_code');
            }
            if (!Schema::hasColumn('accounts', 'opening_balance')) {
                $table->decimal('opening_balance', 15, 2)->default(0)->after('account_type');
            }
            if (!Schema::hasColumn('accounts', 'opening_balance_type')) {
                $table->string('opening_balance_type', 10)->nullable()->after('opening_balance');
            }
            if (!Schema::hasColumn('accounts', 'current_balance')) {
                $table->decimal('current_balance', 15, 2)->default(0)->after('opening_balance_type');
            }
            if (!Schema::hasColumn('accounts', 'is_system')) {
                $table->boolean('is_system')->default(false)->after('current_balance');
            }
            if (!Schema::hasColumn('accounts', 'is_reconciliation_enabled')) {
                $table->boolean('is_reconciliation_enabled')->default(false)->after('is_system');
            }
            if (!Schema::hasColumn('accounts', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('accounts', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('accounts', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('accounts')->whereNull('account_name')->update(['account_name' => DB::raw('COALESCE(name, "Account")')]);
        DB::table('accounts')->whereNull('account_code')->update(['account_code' => DB::raw('COALESCE(code, CONCAT("ACC-", id))')]);
    }

    private function accountingTables(): void
    {
        if (!Schema::hasTable('business_account_settings')) {
            Schema::create('business_account_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                foreach ([
                    'cash_account_id', 'default_bank_account_id', 'accounts_receivable_id', 'accounts_payable_id',
                    'sales_account_id', 'purchase_account_id', 'sales_return_account_id', 'purchase_return_account_id',
                    'inventory_account_id', 'cost_of_goods_sold_account_id', 'output_cgst_account_id',
                    'output_sgst_account_id', 'output_igst_account_id', 'input_cgst_account_id', 'input_sgst_account_id',
                    'input_igst_account_id', 'output_cess_account_id', 'input_cess_account_id',
                    'discount_allowed_account_id', 'discount_received_account_id', 'round_off_account_id',
                    'shipping_income_account_id', 'shipping_expense_account_id', 'customer_advance_account_id',
                    'supplier_advance_account_id',
                ] as $column) {
                    $table->foreignId($column)->nullable()->constrained('accounts')->nullOnDelete();
                }
                $table->timestamps();
                $table->unique('business_id');
            });
        }

        if (!Schema::hasTable('journal_vouchers')) {
            Schema::create('journal_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('voucher_number', 50);
                $table->string('voucher_type', 30)->index();
                $table->date('voucher_date');
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reference_number')->nullable();
                $table->text('narration')->nullable();
                $table->decimal('total_debit', 15, 2)->default(0);
                $table->decimal('total_credit', 15, 2)->default(0);
                $table->string('status', 20)->default('draft')->index();
                $table->boolean('is_system_generated')->default(false);
                $table->foreignId('reversal_of_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
                $table->foreignId('reversed_by_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'voucher_number']);
                $table->index(['business_id', 'reference_type', 'reference_id'], 'journal_reference_index');
            });
        }

        if (!Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('journal_voucher_id')->constrained('journal_vouchers')->cascadeOnDelete();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->decimal('debit_amount', 15, 2)->default(0);
                $table->decimal('credit_amount', 15, 2)->default(0);
                $table->date('due_date')->nullable();
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->text('narration')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'account_id']);
                $table->index(['customer_id', 'supplier_id']);
            });
        }

        if (!Schema::hasTable('ledger_allocations')) {
            Schema::create('ledger_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
                $table->string('allocation_type', 40);
                $table->string('reference_type');
                $table->unsignedBigInteger('reference_id');
                $table->decimal('original_amount', 15, 2);
                $table->decimal('allocated_amount', 15, 2);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('write_off_amount', 15, 2)->default(0);
                $table->date('allocation_date');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'reference_type', 'reference_id'], 'allocation_reference_index');
            });
        }

        foreach (['receipt_vouchers' => 'customer_id', 'payment_vouchers' => 'supplier_id'] as $tableName => $partyColumn) {
            if (!Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table) use ($partyColumn, $tableName) {
                    $table->id();
                    $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                    $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                    $table->foreignId($partyColumn)->constrained($partyColumn === 'customer_id' ? 'customers' : 'suppliers')->restrictOnDelete();
                    $table->string('voucher_number', 50);
                    $table->date($tableName === 'receipt_vouchers' ? 'receipt_date' : 'payment_date');
                    $table->string($tableName === 'receipt_vouchers' ? 'receipt_mode' : 'payment_mode', 30);
                    $table->foreignId('cash_bank_account_id')->constrained('accounts')->restrictOnDelete();
                    $table->decimal('amount', 15, 2);
                    $table->decimal($tableName === 'receipt_vouchers' ? 'discount_allowed' : 'discount_received', 15, 2)->default(0);
                    $table->decimal('write_off_amount', 15, 2)->default(0);
                    $table->string('reference_number')->nullable();
                    $table->string('instrument_number')->nullable();
                    $table->date('instrument_date')->nullable();
                    $table->string('bank_name')->nullable();
                    $table->text('remarks')->nullable();
                    $table->string('status', 20)->default('draft')->index();
                    $table->foreignId('journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                    $table->timestamp('approved_at')->nullable();
                    $table->timestamps();
                    $table->unique(['business_id', 'voucher_number']);
                });
            }
        }

        if (!Schema::hasTable('accounting_periods')) {
            Schema::create('accounting_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('financial_year', 20);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 20)->default('open')->index();
                $table->timestamp('locked_at')->nullable();
                $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'financial_year']);
            });
        }
    }

    private function seedSystemAccounts(): void
    {
        $groups = [
            ['CA', 'Current Assets', 'asset', 'debit'], ['CASH', 'Cash and Cash Equivalents', 'asset', 'debit'],
            ['BANK', 'Bank Accounts', 'asset', 'debit'], ['AR', 'Accounts Receivable', 'asset', 'debit'],
            ['INV', 'Inventory', 'asset', 'debit'], ['CL', 'Current Liabilities', 'liability', 'credit'],
            ['AP', 'Accounts Payable', 'liability', 'credit'], ['TAX', 'Duties and Taxes', 'liability', 'credit'],
            ['CAP', 'Capital', 'equity', 'credit'], ['SALE', 'Sales Income', 'income', 'credit'],
            ['PUR', 'Purchase Accounts', 'expense', 'debit'], ['EXP', 'Direct Expenses', 'expense', 'debit'],
            ['INC', 'Other Income', 'income', 'credit'],
        ];

        foreach (DB::table('companies')->get(['id']) as $company) {
            $ids = [];
            foreach ($groups as $group) {
                DB::table('account_groups')->updateOrInsert(
                    ['business_id' => $company->id, 'group_code' => $group[0]],
                    ['group_name' => $group[1], 'group_type' => $group[2], 'nature' => $group[3], 'is_system' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
                );
                $ids[$group[0]] = DB::table('account_groups')->where('business_id', $company->id)->where('group_code', $group[0])->value('id');
            }

            $accounts = [
                'CASH' => ['Cash Account', 'cash', 'CASH'], 'BANK' => ['Default Bank Account', 'bank', 'BANK'],
                'AR' => ['Accounts Receivable', 'customer', 'AR'], 'AP' => ['Accounts Payable', 'supplier', 'AP'],
                'SALES' => ['Sales Account', 'income', 'SALE'], 'PURCHASE' => ['Purchase Account', 'expense', 'PUR'],
                'SALESRET' => ['Sales Return Account', 'income', 'SALE'], 'PURRET' => ['Purchase Return Account', 'expense', 'PUR'],
                'INVENTORY' => ['Inventory Account', 'inventory', 'INV'], 'COGS' => ['Cost of Goods Sold', 'expense', 'EXP'],
                'OCGST' => ['Output CGST', 'tax', 'TAX'], 'OSGST' => ['Output SGST', 'tax', 'TAX'], 'OIGST' => ['Output IGST', 'tax', 'TAX'], 'OCESS' => ['Output Cess', 'tax', 'TAX'],
                'ICGST' => ['Input CGST', 'tax', 'TAX'], 'ISGST' => ['Input SGST', 'tax', 'TAX'], 'IIGST' => ['Input IGST', 'tax', 'TAX'], 'ICESS' => ['Input Cess', 'tax', 'TAX'],
                'DISC-A' => ['Discount Allowed', 'expense', 'EXP'], 'DISC-R' => ['Discount Received', 'income', 'INC'],
                'ROUND' => ['Round Off', 'adjustment', 'EXP'], 'SHIP-I' => ['Shipping Income', 'income', 'INC'], 'SHIP-E' => ['Shipping Expense', 'expense', 'EXP'],
                'CUST-ADV' => ['Customer Advance', 'customer', 'CL'], 'SUP-ADV' => ['Supplier Advance', 'supplier', 'CA'],
            ];

            foreach ($accounts as $code => $account) {
                DB::table('accounts')->updateOrInsert(
                    ['business_id' => $company->id, 'account_code' => $code],
                    ['account_group_id' => $ids[$account[2]], 'account_name' => $account[0], 'name' => $account[0], 'account_type' => $account[1], 'code' => $code, 'is_system' => true, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()]
                );
            }

            $accountId = fn ($code) => DB::table('accounts')->where('business_id', $company->id)->where('account_code', $code)->value('id');
            DB::table('business_account_settings')->updateOrInsert(
                ['business_id' => $company->id],
                [
                    'cash_account_id' => $accountId('CASH'), 'default_bank_account_id' => $accountId('BANK'),
                    'accounts_receivable_id' => $accountId('AR'), 'accounts_payable_id' => $accountId('AP'),
                    'sales_account_id' => $accountId('SALES'), 'purchase_account_id' => $accountId('PURCHASE'),
                    'sales_return_account_id' => $accountId('SALESRET'), 'purchase_return_account_id' => $accountId('PURRET'),
                    'inventory_account_id' => $accountId('INVENTORY'), 'cost_of_goods_sold_account_id' => $accountId('COGS'),
                    'output_cgst_account_id' => $accountId('OCGST'), 'output_sgst_account_id' => $accountId('OSGST'), 'output_igst_account_id' => $accountId('OIGST'), 'output_cess_account_id' => $accountId('OCESS'),
                    'input_cgst_account_id' => $accountId('ICGST'), 'input_sgst_account_id' => $accountId('ISGST'), 'input_igst_account_id' => $accountId('IIGST'), 'input_cess_account_id' => $accountId('ICESS'),
                    'discount_allowed_account_id' => $accountId('DISC-A'), 'discount_received_account_id' => $accountId('DISC-R'), 'round_off_account_id' => $accountId('ROUND'),
                    'shipping_income_account_id' => $accountId('SHIP-I'), 'shipping_expense_account_id' => $accountId('SHIP-E'),
                    'customer_advance_account_id' => $accountId('CUST-ADV'), 'supplier_advance_account_id' => $accountId('SUP-ADV'),
                    'updated_at' => now(), 'created_at' => now(),
                ]
            );
        }
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) return;
        $names = ['view chart of accounts','manage chart of accounts','view customer receipts','create customer receipt','approve customer receipt','reverse customer receipt','view supplier payments','create supplier payment','approve supplier payment','reverse supplier payment','create contra voucher','create journal voucher','approve journal voucher','reverse journal voucher','post to control accounts','view customer ledger','view supplier ledger','view cash book','view bank book','view account ledger','view customer outstanding','view supplier outstanding','manage opening balances','view account balances','view financial reports','post backdated voucher','close accounting period','reopen accounting period'];
        foreach ($names as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['module' => 'accounting', 'description' => ucfirst($name), 'updated_at' => now(), 'created_at' => now()]);
        }
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        foreach ([1, 2] as $roleId) foreach ($ids as $id) DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['updated_at' => now(), 'created_at' => now()]);
    }

    public function down(): void
    {
        // Accounting history is retained intentionally.
    }
};
