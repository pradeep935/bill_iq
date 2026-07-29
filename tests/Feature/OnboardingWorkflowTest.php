<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OnboardingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_can_access_onboarding(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('BillIQ Setup Progress')
            ->assertSee('Setup Checklist');
    }

    public function test_unauthorized_user_receives_403(): void
    {
        [$businessId, $user] = $this->businessUser(3);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertForbidden();
    }

    public function test_onboarding_data_is_scoped_by_business_id(): void
    {
        [$businessId, $user] = $this->businessUser(2);
        $otherBusinessId = DB::table('companies')->insertGetId(['name' => 'Other Business', 'state' => 'Delhi', 'financial_year' => '2026-27', 'created_at' => now(), 'updated_at' => now()]);
        $this->branch($otherBusinessId, 'Other Branch', 'OTH');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('No active branches found')
            ->assertDontSee('Other Branch');
    }

    public function test_business_profile_completion_is_calculated_from_company_fields(): void
    {
        [$businessId, $user] = $this->businessUser(2, ['state' => null]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('Business Profile')
            ->assertSee('Missing: State');

        DB::table('companies')->where('id', $businessId)->update(['state' => 'UP']);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('All supported business profile fields are configured');
    }

    public function test_financial_year_completion_uses_accounting_periods(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('No accounting period has been created');

        DB::table('accounting_periods')->insert([
            'business_id' => $businessId,
            'financial_year' => '2026-27',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('Current date belongs to an active financial year');
    }

    public function test_gst_step_handles_registered_and_non_registered_businesses(): void
    {
        [$businessId, $user] = $this->businessUser(2, ['state' => 'UP']);
        $this->hsn();

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('Business profile is treated as non-GST');

        DB::table('companies')->where('id', $businessId)->update(['gstin' => '09ABCDE1234F1Z5']);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('GSTIN, state and GST master records are available');
    }

    public function test_branch_and_warehouse_steps_reflect_real_records(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('No active branches found')
            ->assertSee('Warehouse setup is locked until at least one branch is created');

        $branchId = $this->branch($businessId, 'Main Branch', 'MAIN');
        $this->warehouse($businessId, $branchId, 'Main Warehouse', 'WH');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('1 branch configured')
            ->assertSee('1 warehouse configured');
    }

    public function test_product_step_checks_required_product_fields(): void
    {
        [$businessId, $user] = $this->businessUser(2, ['state' => 'UP']);
        $this->minimalPrerequisites($businessId);
        DB::table('products')->insert($this->product($businessId, ['unit_id' => null, 'hsn_code' => null, 'selling_price' => 0]));

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('products are missing required fields');
    }

    public function test_opening_stock_uses_stock_ledger_data(): void
    {
        [$businessId, $user] = $this->businessUser(2, ['state' => 'UP']);
        [$branchId, $warehouseId, $productId] = $this->minimalPrerequisites($businessId);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('no opening stock ledger decision has been posted');

        DB::table('stock_ledgers')->insert([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'transaction_type' => 'opening_stock',
            'reference_type' => 'test',
            'reference_id' => 1,
            'quantity_in' => 10,
            'quantity_out' => 0,
            'unit_cost' => 100,
            'stock_value' => 1000,
            'transaction_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('1 stock line configured');
    }

    public function test_optional_and_coming_soon_steps_do_not_control_required_progress(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('Brand setup is optional')
            ->assertSee('Dedicated invoice template setup is not available in this build yet');
    }

    public function test_invalid_filter_falls_back_to_all_and_no_pagination_is_shown(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding?filter=bad')
            ->assertOk()
            ->assertSee('Business Profile')
            ->assertSee('First Test Sale')
            ->assertDontSee('Previous')
            ->assertDontSee('1-3 of 3');
    }

    public function test_next_recommended_step_updates_after_prerequisites(): void
    {
        [$businessId, $user] = $this->businessUser(2, ['state' => 'UP']);
        $this->hsn();

        DB::table('accounting_periods')->insert([
            'business_id' => $businessId,
            'financial_year' => '2026-27',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/onboarding')
            ->assertOk()
            ->assertSee('Create at least one active branch');
    }

    private function businessUser(int $roleId, array $company = []): array
    {
        $businessId = DB::table('companies')->insertGetId(array_merge([
            'name' => 'ABC Retail Pvt Ltd',
            'state' => 'UP',
            'financial_year' => '2026-27',
            'created_at' => now(),
            'updated_at' => now(),
        ], $company));

        $user = User::factory()->create([
            'tenant_id' => $businessId,
            'role_id' => $roleId,
            'is_active' => 1,
            'status' => 'active',
        ]);

        return [$businessId, $user];
    }

    private function minimalPrerequisites(int $businessId): array
    {
        $branchId = $this->branch($businessId, 'Main Branch', 'MAIN');
        $warehouseId = $this->warehouse($businessId, $branchId, 'Main Warehouse', 'WH');
        $unitId = DB::table('units')->insertGetId(['code' => 'PCS' . $businessId, 'name' => 'Piece', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $categoryId = DB::table('product_categories')->insertGetId(['business_id' => $businessId, 'name' => 'General', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->hsn();
        DB::table('accounting_periods')->insert(['business_id' => $businessId, 'financial_year' => '2026-27', 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonth()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_methods')->insert(['business_id' => $businessId, 'name' => 'Cash', 'type' => 'cash', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounts')->insert(['business_id' => $businessId, 'name' => 'Cash Account', 'code' => 'CASH', 'account_type' => 'cash', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounts')->insert(['business_id' => $businessId, 'name' => 'Bank Account', 'code' => 'BANK', 'account_type' => 'bank', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $productId = DB::table('products')->insertGetId($this->product($businessId, ['unit_id' => $unitId, 'category_id' => $categoryId]));

        return [$branchId, $warehouseId, $productId];
    }

    private function product(int $businessId, array $extra = []): array
    {
        return array_merge([
            'business_id' => $businessId,
            'company_id' => $businessId,
            'name' => 'Item',
            'sku' => 'ITM-' . uniqid(),
            'product_type' => 'goods',
            'item_type' => 'stock',
            'tracking_type' => 'none',
            'hsn_code' => '1001',
            'sale_price' => 100,
            'selling_price' => 100,
            'purchase_price' => 80,
            'gst_rate' => 18,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra);
    }

    private function branch(int $businessId, string $name, string $code): int
    {
        return DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => $name, 'code' => $code, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function warehouse(int $businessId, int $branchId, string $name, string $code): int
    {
        return DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branchId, 'name' => $name, 'code' => $code, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function hsn(): void
    {
        DB::table('hsn_masters')->updateOrInsert(
            ['hsn_code' => '1001', 'effective_from' => now()->subYear()->toDateString()],
            ['description' => 'General goods', 'gst_rate' => 18, 'cess_rate' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );
    }
}
