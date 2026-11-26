<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\BusinessAccountSetting;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\AccountingPostingService;
use App\Services\CustomerService;
use App\Services\Dashboard\BusinessDashboardService;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithCompanyAccess;
use Tests\TestCase;

class LedgerBalanceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithCompanyAccess;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        }
    }

    public function test_money_received_at_billing_is_not_counted_as_customer_due_and_reversals_cancel_out(): void
    {
        [$businessId, $user, , , , $customerId] = $this->fixture();
        $this->loginBusiness($user, $businessId);
        $account = fn (string $code, string $type) => Account::create(['business_id' => $businessId, 'account_code' => $code, 'account_name' => $code, 'name' => $code, 'account_type' => $type, 'status' => 'active'])->id;
        [$cash, $receivable, $income] = [$account('CASH', 'cash'), $account('AR', 'asset'), $account('SALES', 'income')];
        BusinessAccountSetting::updateOrCreate(['business_id' => $businessId], ['cash_account_id' => $cash, 'accounts_receivable_id' => $receivable, 'sales_account_id' => $income]);
        $posting = app(AccountingPostingService::class);
        $journal = fn (int $debit, float $amount) => $posting->createJournalVoucher([
            'business_id' => $businessId, 'voucher_type' => 'journal', 'voucher_date' => now()->toDateString(), 'status' => 'approved',
            'entries' => [
                ['account_id' => $debit, 'customer_id' => $customerId, 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_id' => $income, 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        ]);

        $journal($cash, 500);               // cash bill: nothing to collect
        $journal($receivable, 120);         // credit bill: to collect
        $mistake = $journal($receivable, 75);
        $posting->reverseJournalVoucher($mistake, 'Entered twice');

        $service = app(CustomerService::class);
        $customer = Customer::findOrFail($customerId);
        $this->assertSame(120.0, (float) $service->present($customer)['current_outstanding']);
        $this->assertSame(120.0, (float) $service->cards()['to_collect']);
        $this->assertSame(1, $service->cards()['owing_count']);
        $this->assertSame([$customerId], $service->list(['balance' => 'due'])->pluck('id')->all());
        $ledger = $service->ledger($customer)['entries'];
        $this->assertCount(3, $ledger, 'The cash line is not part of the customer ledger.');
        $this->assertSame(120.0, (float) collect($ledger)->last()['running_balance']);
    }

    public function test_a_reversed_entry_is_removed_once_from_every_report(): void
    {
        [$businessId, $user, , , , $customerId] = $this->fixture();
        $this->loginBusiness($user, $businessId);
        $account = function (string $code, string $type, string $statement, string $section, string $normal) use ($businessId) {
            $group = AccountGroup::create(['business_id' => $businessId, 'group_code' => $code, 'group_name' => $code, 'group_type' => $type, 'nature' => $normal, 'financial_statement_type' => $statement, 'financial_statement_section' => $section, 'normal_balance' => $normal, 'status' => 'active']);

            return Account::create(['business_id' => $businessId, 'account_group_id' => $group->id, 'account_code' => $code, 'account_name' => $code, 'name' => $code, 'account_type' => $type, 'financial_statement_section' => $section, 'status' => 'active'])->id;
        };
        $cash = $account('CASH', 'cash', 'balance_sheet', 'Cash and Cash Equivalents', 'debit');
        $receivable = $account('AR', 'asset', 'balance_sheet', 'Accounts Receivable', 'debit');
        $income = $account('SALES', 'income', 'profit_and_loss', 'Sales Revenue', 'credit');
        BusinessAccountSetting::updateOrCreate(['business_id' => $businessId], ['cash_account_id' => $cash, 'accounts_receivable_id' => $receivable, 'sales_account_id' => $income]);
        $posting = app(AccountingPostingService::class);
        $journal = fn (int $debit, float $amount, string $status = 'approved') => $posting->createJournalVoucher([
            'business_id' => $businessId, 'voucher_type' => 'journal', 'voucher_date' => now()->toDateString(), 'status' => $status,
            'entries' => [
                ['account_id' => $debit, 'customer_id' => $customerId, 'debit_amount' => $amount, 'credit_amount' => 0],
                ['account_id' => $income, 'debit_amount' => 0, 'credit_amount' => $amount],
            ],
        ]);
        $journal($cash, 500);
        $journal($receivable, 120);
        $posting->reverseJournalVoucher($journal($receivable, 75), 'Entered twice');
        $posting->reverseJournalVoucher($journal($cash, 40), 'Wrong amount');
        $journal($cash, 999, 'draft');

        $reports = app(FinancialReportService::class);
        $trial = $reports->getTrialBalance();
        $closing = collect($trial['rows'])->keyBy('account_id');
        $this->assertSame(500.0, (float) $closing[$cash]['closing_debit']);
        $this->assertSame(120.0, (float) $closing[$receivable]['closing_debit']);
        $this->assertSame(620.0, (float) $closing[$income]['closing_credit']);
        $this->assertTrue($trial['is_balanced']);
        $this->assertSame(620.0, (float) $reports->getProfitAndLoss()['net_profit']);
        $sheet = $reports->getBalanceSheet();
        $this->assertSame(620.0, (float) $sheet['assets_total']);
        $this->assertTrue($sheet['is_balanced']);
        $this->assertSame(120.0, (float) $reports->getReceivableSummary()['totals']['closing_balance']);
        $this->assertSame(500.0, (float) $reports->getLedgerReport(['account_id' => $cash])['closing_balance']);

        $dashboard = new \ReflectionMethod(BusinessDashboardService::class, 'accountTypeBalance');
        $dashboard->setAccessible(true);
        $this->assertSame(500.0, $dashboard->invoke(app(BusinessDashboardService::class), $businessId, ['cash']), 'Drafts are not cash in hand.');
    }

    protected function fixture(): array
    {
        $businessId = DB::table('companies')->insertGetId(['name' => 'Bill IQ Test', 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->createAccessibleUser($businessId, ['role_id' => 1, 'is_active' => 1, 'status' => 'active']);
        $branch = DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branch, 'name' => 'Store', 'code' => 'ST', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $customer = DB::table('customers')->insertGetId(['company_id' => $businessId, 'name' => 'Acme Customer', 'business_id' => $businessId, 'customer_name' => 'Acme Customer', 'mobile' => '9999999999', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Inventory Item', 'sku' => 'INV-1', 'product_type' => 'goods', 'item_type' => 'stock', 'track_inventory' => true, 'tracking_type' => 'none', 'status' => 'active']);

        return [$businessId, $user, $product, $branch, $warehouse, $customer];
    }

    protected function loginBusiness(User $user, int $businessId): void
    {
        $this->grantCompanyAccess($user, $businessId, 'owner');
        Auth::login($user);
        session(['business_id' => $businessId]);
    }
}
