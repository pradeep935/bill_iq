<?php
namespace Tests\Feature;

use App\Models\{Account, BusinessAccountSetting, ExpenseCategory, IncomeCategory, User, JournalEntry};
use App\Services\ExpenseIncomeService;
use Database\Seeders\ExpenseIncomeCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseIncomeCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing(): array
    {
        // Skip MySQL-only legacy column repairs and the unrelated HSN index migration on SQLite.
        $mysqlOnly = ['2026_07_21_000023', '2026_07_21_000024', '2026_07_21_000027', '2026_07_21_000034', '2026_08_05_000001', '2026_08_05_000002', '2026_08_11_000003', '2026_08_11_000004'];
        $paths = collect(glob(database_path('migrations/*.php')))->reject(function ($path) use ($mysqlOnly) {
            return DB::getDriverName() === 'sqlite' && in_array(substr(basename($path), 0, 17), $mysqlOnly, true);
        })->values()->all();
        return ['--path' => $paths, '--realpath' => true];
    }

    private function setupBusiness(): int
    {
        $id = DB::table('companies')->insertGetId(['name' => 'Category Test']);
        $user = User::factory()->create(['tenant_id' => $id, 'role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        DB::table('company_user_access')->insert([
            'company_id' => $id,
            'user_id' => $user->id,
            'access_role' => 'admin',
            'is_default' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($user)->withSession(['business_id' => $id]);
        foreach ([['SHIP-E','expense'], ['SHIP-I','income'], ['CASH','cash'], ['SALES-RET','expense']] as [$code,$type]) {
            Account::create(['business_id' => $id, 'account_code' => $code, 'account_name' => $code, 'name' => $code, 'account_type' => $type, 'status' => 'active']);
        }
        return $id;
    }

    public function test_defaults_are_tenant_scoped_preserve_users_and_initialize_with_settings(): void
    {
        $id = $this->setupBusiness();
        BusinessAccountSetting::create(['business_id' => $id]);
        $this->assertDatabaseCount('expense_categories', 1);
        $this->assertDatabaseCount('income_categories', 1);
        $category = ExpenseCategory::first();
        $category->update(['category_name' => 'User shipping', 'status' => 'inactive']);
        $before = $category->fresh()->getAttributes();
        ExpenseIncomeCategorySeeder::seedForBusiness($id);
        ExpenseIncomeCategorySeeder::seedForBusiness($id);
        $this->assertSame($before, $category->fresh()->getAttributes());
        $this->assertDatabaseCount('expense_categories', 1);
        $other = DB::table('companies')->insertGetId(['name' => 'Empty chart']);
        ExpenseIncomeCategorySeeder::seedForBusiness($other);
        $this->assertDatabaseMissing('expense_categories', ['business_id' => $other]);
        $category->delete();
        ExpenseIncomeCategorySeeder::seedForBusiness($id);
        $this->assertDatabaseCount('expense_categories', 1);
    }

    public function test_create_edit_status_validation_and_reference_mappings(): void
    {
        $id = $this->setupBusiness();
        config(['app.url' => 'http://localhost']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
        foreach (['expense','income'] as $kind) {
            $account = Account::where('account_code', $kind === 'expense' ? 'SHIP-E' : 'SHIP-I')->first();
            $payload = ['category_code' => 'CUSTOM', 'category_name' => 'Custom', $kind.'_account_id' => $account->id, 'status' => 'active'];
            $url = '/app/accounting/'.$kind.'-categories';
            $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['category_code','category_name',$kind.'_account_id']);
            $category = $this->postJson($url, $payload)->assertCreated()->json('category');
            $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors(['category_code','category_name']);
            $this->putJson($url.'/'.$category['id'], array_merge($payload, ['status' => 'inactive']))->assertOk();
            $this->getJson($url.'/list')->assertOk()->assertJsonPath('categories.0.account.id', $account->id)->assertJsonPath('categories.0.status', 'inactive');
            $this->assertCount(0, app(ExpenseIncomeService::class)->references()[$kind.'_categories']);
            $this->putJson($url.'/'.$category['id'], $payload)->assertOk();
            $this->assertCount(1, app(ExpenseIncomeService::class)->references()[$kind.'_categories']);
            $bad = Account::where('account_code','SALES-RET')->first();
            $this->postJson($url, array_merge($payload, ['category_code'=>'BAD','category_name'=>'Bad', $kind.'_account_id'=>$bad->id]))->assertUnprocessable()->assertJsonValidationErrors([$kind.'_account_id']);
            $foreignId = DB::table('companies')->insertGetId(['name' => 'Foreign']);
            $foreign = Account::create(['business_id'=>$foreignId,'account_code'=>'FOREIGN','account_name'=>'Foreign','name'=>'Foreign','account_type'=>$kind,'status'=>'active']);
            $this->postJson($url, array_merge($payload, ['category_code'=>'FOREIGN','category_name'=>'Foreign', $kind.'_account_id'=>$foreign->id]))->assertUnprocessable()->assertJsonValidationErrors([$kind.'_account_id']);
        }
    }

    public function test_petty_cash_default_setup_is_idempotent_and_preserves_mappings(): void
    {
        $business = $this->setupBusiness();
        $settings = BusinessAccountSetting::create(['business_id' => $business]);
        $account = Account::where('business_id', $business)->where('account_code', 'PETTY-ADV')->firstOrFail();
        $this->assertSame('asset', $account->account_type);
        $this->assertEquals($account->id, $settings->fresh()->petty_cash_advance_account_id);
        $before = $account->getAttributes();
        \Database\Seeders\PettyCashAccountSeeder::seedForBusiness($business);
        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertEquals(1, Account::where('business_id', $business)->where('account_code', 'PETTY-ADV')->count());
        $settings->update(['petty_cash_advance_account_id' => null, 'supplier_advance_account_id' => $account->id]);
        \Database\Seeders\PettyCashAccountSeeder::seedForBusiness($business);
        $this->assertNull($settings->fresh()->petty_cash_advance_account_id);
        $this->assertEquals($account->id, $settings->fresh()->supplier_advance_account_id);
    }

    public function test_petty_cash_draft_and_issue_workflow(): void
    {
        $business = $this->setupBusiness();
        config(['app.url' => 'http://localhost']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
        $cash = Account::where('account_code', 'CASH')->first();
        $advanceAccount = Account::create(['business_id' => $business, 'account_code' => 'PETTY-ADV', 'account_name' => 'Employee Petty Cash Advance', 'name' => 'Employee Petty Cash Advance', 'account_type' => 'asset', 'status' => 'active']);
        BusinessAccountSetting::create(['business_id' => $business, 'petty_cash_advance_account_id' => $advanceAccount->id]);
        $branch = DB::table('branches')->insertGetId(['business_id' => $business, 'name' => 'Default Branch', 'code' => 'DEFAULT', 'status' => 'active']);
        $employee = \App\Models\Employee::create(['business_id' => $business, 'branch_id' => $branch, 'employee_code' => 'EMP-001', 'first_name' => 'Rahul', 'display_name' => 'Rahul Sharma', 'mobile' => '1234567890', 'joining_date' => '2026-01-01', 'status' => 'active', 'employment_status' => 'active']);
        $url = '/app/accounting/petty-cash';
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['employee_id', 'cash_account_id', 'advance_date', 'amount', 'purpose', 'status']);
        $payload = ['branch_id' => '', 'employee_id' => $employee->id, 'cash_account_id' => $cash->id, 'advance_date' => '2026-09-13', 'amount' => 150, 'purpose' => 'Office supplies', 'status' => 'draft'];
        $this->postJson($url, array_merge($payload, ['status' => 'settled']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $draft = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('advance.status', 'draft')->json('advance');
        $this->assertDatabaseCount('journal_entries', 0);
        $this->postJson($url, array_merge($payload, ['employee_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $employee->update(['status' => 'inactive']);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->assertCount(0, app(ExpenseIncomeService::class)->references()['petty_cash_employees']);
        $employee->update(['status' => 'active', 'employment_status' => 'resigned']);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $employee->update(['employment_status' => 'active']);
        $foreignBusiness = DB::table('companies')->insertGetId(['name' => 'Other business']);
        $employee->update(['business_id' => $foreignBusiness]);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->assertCount(0, app(ExpenseIncomeService::class)->references()['petty_cash_employees']);
        $this->getJson($url.'/list')->assertOk()->assertJsonPath('advances.0.employee', null);
        $employee->update(['business_id' => $business]);
        $otherBranch = DB::table('branches')->insertGetId(['business_id' => $business, 'name' => 'Other Branch', 'code' => 'OTHER', 'status' => 'active']);
        $this->postJson($url, array_merge($payload, ['branch_id' => $otherBranch]))->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $employee->delete();
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->getJson($url.'/list')->assertOk()->assertJsonPath('advances.0.employee.display_name', 'Rahul Sharma');
        $employee->restore();
        $this->assertCount(1, app(ExpenseIncomeService::class)->references()['petty_cash_employees']);
        // Posting failure happens after advance insertion, so the whole transaction must roll back.
        BusinessAccountSetting::where('business_id', $business)->update(['petty_cash_advance_account_id' => null, 'supplier_advance_account_id' => null]);
        $this->postJson($url, array_merge($payload, ['status' => 'issued']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('petty_cash_advances', 1);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertEquals(0, $cash->fresh()->current_balance);
        BusinessAccountSetting::where('business_id', $business)->update(['petty_cash_advance_account_id' => $advanceAccount->id]);
        $issued = $this->postJson($url, array_merge($payload, ['status' => 'issued']))->assertCreated()->assertJsonPath('advance.status', 'issued')->json('advance');
        $this->getJson($url.'/list')->assertOk()->assertJsonPath('advances.0.cash_account.id', $cash->id)->assertJsonPath('advances.0.purpose', 'Office supplies');
        $entries = JournalEntry::where('journal_voucher_id', $issued['journal_voucher_id'])->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(150, $entries->where('account_id', $cash->id)->sum('credit_amount'));
        $this->assertEquals(150, $entries->where('account_id', $advanceAccount->id)->sum('debit_amount'));
        $this->assertDatabaseHas('petty_cash_advances', ['id' => $draft['id'], 'employee_id' => $employee->id, 'balance_amount' => 150, 'settled_amount' => 0]);
        $before = JournalEntry::count();
        $cashBefore = (float) $cash->fresh()->current_balance;
        $edit = array_merge($payload, ['amount' => 100, 'purpose' => 'Edited draft']);
        $this->putJson($url.'/'.$draft['id'], $edit)->assertOk()->assertJsonPath('advance.status', 'draft');
        $this->assertEquals($before, JournalEntry::count());
        $this->putJson($url.'/'.$draft['id'], array_merge($edit, ['status' => 'issued']))->assertUnprocessable();
        $employee->update(['status' => 'inactive']);
        $this->postJson($url.'/'.$draft['id'].'/issue')->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $employee->update(['status' => 'active']);
        $result = $this->postJson($url.'/'.$draft['id'].'/issue')->assertOk()->assertJsonPath('advance.status', 'issued')->json('advance');
        $this->postJson($url.'/'.$draft['id'].'/issue')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertEquals($before + 2, JournalEntry::count());
        $this->assertEquals($cashBefore - 100, (float) $cash->fresh()->current_balance);
        $this->putJson($url.'/'.$draft['id'], $edit)->assertUnprocessable();
        $this->deleteJson($url.'/'.$draft['id'])->assertUnprocessable();
        $another = $this->postJson($url, $payload)->assertCreated()->json('advance');
        $this->deleteJson($url.'/'.$another['id'])->assertOk();
        $this->assertDatabaseMissing('petty_cash_advances', ['id' => $another['id']]);
        $this->assertEquals($before + 2, JournalEntry::count());
        $this->assertEquals($cashBefore - 100, (float) $cash->fresh()->current_balance);
        $this->assertEquals(100, JournalEntry::where('journal_voucher_id', $result['journal_voucher_id'])->where('account_id', $cash->id)->sum('credit_amount'));
        $book = $this->getJson('/app/accounting/cash-bank-book?account_id='.$cash->id)->assertOk()->json();
        $rows = collect($book)->where('reference_number', $draft['voucher_number']);
        $this->assertCount(1, $rows);
        $this->assertEquals(100, $rows->sum('credit_amount'));


    }

    public function test_petty_cash_draft_delete_is_tenant_scoped_and_never_touches_accounting(): void
    {
        $business = $this->setupBusiness();
        config(['app.url' => 'http://localhost']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
        $cash = Account::where('account_code', 'CASH')->first();
        $advanceAccount = Account::create(['business_id' => $business, 'account_code' => 'PETTY-ADV', 'account_name' => 'Employee Petty Cash Advance', 'name' => 'Employee Petty Cash Advance', 'account_type' => 'asset', 'status' => 'active']);
        BusinessAccountSetting::create(['business_id' => $business, 'petty_cash_advance_account_id' => $advanceAccount->id]);
        $branch = DB::table('branches')->insertGetId(['business_id' => $business, 'name' => 'Default Branch', 'code' => 'DEFAULT', 'status' => 'active']);
        $employee = \App\Models\Employee::create(['business_id' => $business, 'branch_id' => $branch, 'employee_code' => 'EMP-001', 'first_name' => 'Rahul', 'display_name' => 'Rahul Sharma', 'mobile' => '1234567890', 'joining_date' => '2026-01-01', 'status' => 'active', 'employment_status' => 'active']);
        $url = '/app/accounting/petty-cash';
        $payload = ['branch_id' => '', 'employee_id' => $employee->id, 'cash_account_id' => $cash->id, 'advance_date' => '2026-09-13', 'amount' => 100, 'purpose' => 'Testing petty cash delete', 'status' => 'draft'];
        $draft = $this->postJson($url, $payload)->assertCreated()->json('advance');
        $issued = $this->postJson($url, array_merge($payload, ['status' => 'issued']))->assertCreated()->json('advance');
        $counts = fn () => [DB::table('journal_vouchers')->count(), JournalEntry::count(), (float) $cash->fresh()->current_balance, (float) $advanceAccount->fresh()->current_balance];
        $before = $counts();

        // Another tenant can neither see nor delete this business's draft.
        $other = DB::table('companies')->insertGetId(['name' => 'Other business']);
        DB::table('company_user_access')->insert([
            'company_id' => $other,
            'user_id' => auth()->id(),
            'access_role' => 'admin',
            'is_default' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withSession(['business_id' => $other])->deleteJson($url.'/'.$draft['id'])->assertNotFound();
        $this->assertDatabaseHas('petty_cash_advances', ['id' => $draft['id'], 'status' => 'draft']);
        $this->withSession(['business_id' => $business]);

        $this->deleteJson($url.'/'.$issued['id'])->assertUnprocessable();
        $this->assertDatabaseHas('petty_cash_advances', ['id' => $issued['id'], 'status' => 'issued']);

        $this->deleteJson($url.'/'.$draft['id'])->assertOk()->assertJsonPath('message', 'Draft advance deleted.');
        $this->assertDatabaseMissing('petty_cash_advances', ['id' => $draft['id']]);
        $this->assertSame($before, $counts());
        $this->assertSame(0, JournalEntry::where('reference_number', $draft['voucher_number'])->count());
        $this->assertSame([$issued['voucher_number']], collect($this->getJson($url.'/list')->assertOk()->json('advances'))->pluck('voucher_number')->all());
    }

    public function test_recurring_template_validation_list_and_status_update(): void
    {
        $business = $this->setupBusiness();
        config(['app.url' => 'http://localhost']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
        ExpenseIncomeCategorySeeder::seedForBusiness($business);
        $category = ExpenseCategory::first();
        $url = '/app/accounting/recurring-expenses';
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['template_name', 'expense_category_id', 'expense_account_id', 'frequency', 'amount', 'next_run_date', 'status']);
        $payload = ['template_name' => 'Monthly Shipping', 'expense_category_id' => $category->id, 'expense_account_id' => $category->expense_account_id, 'frequency' => 'monthly', 'interval_value' => 2, 'start_date' => '2026-09-13', 'next_run_date' => '2026-10-13', 'amount' => 150, 'tax_type' => 'exclusive', 'gst_rate' => 0, 'auto_create_draft' => true, 'auto_post' => false, 'approval_required' => true, 'status' => 'active'];
        $result = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('template.account.id', $category->expense_account_id)->json('template');
        $this->getJson($url.'/list')->assertOk()->assertJsonPath('recurring.0.category.id', $category->id)->assertJsonPath('recurring.0.account.id', $category->expense_account_id);
        $this->putJson($url.'/'.$result['id'], array_merge($payload, ['status' => 'paused']))->assertOk()->assertJsonPath('template.status', 'paused')->assertJsonPath('template.interval_value', 2);
        $this->assertDatabaseCount('recurring_expense_templates', 1);
        $this->assertDatabaseCount('expense_vouchers', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_income_form_payload_posts_150_and_exposes_validation_errors(): void
    {
        $id = $this->setupBusiness();
        config(['app.url' => 'http://localhost']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://localhost');
        ExpenseIncomeCategorySeeder::seedForBusiness($id);
        $branch = DB::table('branches')->insertGetId(['business_id' => $id, 'name' => 'Default Branch', 'code' => 'DEFAULT', 'status' => 'active']);
        $category = IncomeCategory::first();
        $cash = Account::where('account_code', 'CASH')->first();
        $payload = ['id' => null, 'branch_id' => $branch, 'income_date' => '2026-09-13', 'income_category_id' => $category->id,
            'income_account_id' => $category->income_account_id, 'received_into_account_id' => $cash->id,
            'customer_id' => '', 'party_name' => 'test customer', 'payment_mode' => 'cash', 'reference_number' => '',
            'tax_type' => 'exclusive', 'taxable_amount' => 150, 'non_taxable_amount' => 0, 'cgst_amount' => 0,
            'sgst_amount' => 0, 'igst_amount' => 0, 'cess_amount' => 0, 'total_amount' => 150, 'status' => 'posted', 'narration' => ''];
        $url = '/app/accounting/other-income';
        $this->postJson($url, array_merge($payload, ['total_amount' => 0]))->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        $this->assertDatabaseCount('other_income_vouchers', 0);
        $result = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('income.status', 'posted')->json('income');
        $this->assertDatabaseHas('other_income_vouchers', ['id' => $result['id'], 'party_name' => 'test customer', 'total_amount' => 150]);
        $this->getJson($url.'/list')->assertOk()->assertJsonPath('income.0.id', $result['id']);
        $entries = JournalEntry::where('journal_voucher_id', $result['journal_voucher_id'])->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(150, $entries->where('account_id', $cash->id)->sum('debit_amount'));
        $this->assertEquals(150, $entries->where('account_id', $category->income_account_id)->sum('credit_amount'));
        $this->postJson($url.'/'.$result['id'].'/post')->assertOk();
        $this->assertDatabaseCount('other_income_vouchers', 1);
        $this->assertCount(2, JournalEntry::where('journal_voucher_id', $result['journal_voucher_id'])->get());
        $this->putJson($url.'/'.$result['id'], $payload)->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_expense_and_income_post_balanced_entries_once(): void
    {
        $id = $this->setupBusiness();
        ExpenseIncomeCategorySeeder::seedForBusiness($id);
        $service = app(ExpenseIncomeService::class);
        $expense = ExpenseCategory::first(); $income = IncomeCategory::first();
        $cash = Account::where('account_code','CASH')->first();
        $voucher = $service->saveExpense(['expense_date'=>'2026-09-13','expense_category_id'=>$expense->id,'expense_account_id'=>$expense->expense_account_id,'paid_from_account_id'=>$cash->id,'payment_mode'=>'cash','payment_status'=>'paid','tax_type'=>'non_taxable','status'=>'posted','items'=>[['expense_category_id'=>$expense->id,'expense_account_id'=>$expense->expense_account_id,'description'=>'Shipping','quantity'=>1,'rate'=>100]]]);
        $other = $service->saveOtherIncome(['income_date'=>'2026-09-13','income_category_id'=>$income->id,'income_account_id'=>$income->income_account_id,'received_into_account_id'=>$cash->id,'payment_mode'=>'cash','tax_type'=>'non_taxable','non_taxable_amount'=>100,'total_amount'=>100,'status'=>'posted']);
        $this->assertSame('posted', $voucher->status); $this->assertSame('posted', $other->status);
        $count = JournalEntry::count();
        $this->assertEquals(200, JournalEntry::sum('debit_amount'));
        $this->assertEquals(200, JournalEntry::sum('credit_amount'));
        $this->assertEquals(100, JournalEntry::where('account_id',$expense->expense_account_id)->sum('debit_amount'));
        $this->assertEquals(100, JournalEntry::where('account_id',$income->income_account_id)->sum('credit_amount'));
        $service->postExpense($voucher->id); $service->postOtherIncome($other->id);
        $this->assertSame($count, JournalEntry::count());
    }
}
