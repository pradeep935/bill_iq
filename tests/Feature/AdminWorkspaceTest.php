<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_access_workspace(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/workspace')
            ->assertOk();
    }

    public function test_unauthorized_staff_receives_403(): void
    {
        [$businessId, $user] = $this->businessUser(3);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/workspace')
            ->assertForbidden();
    }

    public function test_workspace_counts_are_tenant_scoped(): void
    {
        [$businessId, $user] = $this->businessUser(2);
        $otherBusinessId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('branches')->insert([
            ['business_id' => $businessId, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['business_id' => $otherBusinessId, 'name' => 'Other', 'code' => 'OTH', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/workspace')
            ->assertOk()
            ->assertSee('Branches')
            ->assertSee('1');
    }

    public function test_branch_warehouse_and_stock_line_counts_are_correct(): void
    {
        [$businessId, $user] = $this->businessUser(2);
        $branch = DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branch, 'name' => 'Store', 'code' => 'ST', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = DB::table('products')->insertGetId([
            'business_id' => $businessId,
            'company_id' => $businessId,
            'name' => 'Item',
            'sku' => 'ITM',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'tracking_type' => 'none',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_ledgers')->insert([
            ['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product, 'transaction_type' => 'opening_stock', 'reference_type' => 'test', 'reference_id' => 1, 'quantity_in' => 10, 'quantity_out' => 0, 'unit_cost' => 5, 'stock_value' => 50, 'transaction_date' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product, 'transaction_type' => 'sale', 'reference_type' => 'test', 'reference_id' => 2, 'quantity_in' => 0, 'quantity_out' => 4, 'unit_cost' => 5, 'stock_value' => 20, 'transaction_date' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/workspace')
            ->assertOk()
            ->assertSee('Branches')
            ->assertSee('Warehouses')
            ->assertSee('Stock Lines');
    }

    public function test_invalid_section_falls_back_to_admin(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/workspace?section=bad')
            ->assertOk()
            ->assertSee('Operational setup metrics');
    }

    public function test_staff_section_returns_staff_metrics(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/workspace?section=staff')
            ->assertOk()
            ->assertSee('Total Employees');
    }

    public function test_onboarding_progress_uses_real_data(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/workspace?section=onboarding')
            ->assertOk()
            ->assertSee('Overall Progress')
            ->assertSee('Business profile completed');
    }

    public function test_saas_section_is_protected_for_business_admin(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/admin/workspace?section=saas')
            ->assertOk()
            ->assertSee('Operational setup metrics')
            ->assertDontSee('Current Subscription Plan');
    }

    public function test_logout_is_post_route(): void
    {
        [$businessId, $user] = $this->businessUser(2);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->post('/logout')
            ->assertRedirect('/');
    }

    private function businessUser(int $roleId): array
    {
        $businessId = DB::table('companies')->insertGetId(['name' => 'ABC Retail Pvt Ltd', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create([
            'tenant_id' => $businessId,
            'role_id' => $roleId,
            'is_active' => 1,
            'status' => 'active',
        ]);

        return [$businessId, $user];
    }
}
