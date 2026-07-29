<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaffWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_access_their_workspace(): void
    {
        [$businessId, $user] = $this->businessUser(3);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/staff/workspace')
            ->assertOk()
            ->assertSee('Staff Workspace')
            ->assertSee($user->name);
    }

    public function test_non_staff_or_admin_user_is_rejected(): void
    {
        [$businessId, $user] = $this->businessUser(99);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/staff/workspace')
            ->assertForbidden();
    }

    public function test_staff_context_is_limited_to_assigned_branch_and_warehouse(): void
    {
        [$businessId, $user] = $this->businessUser(3);
        $assignedBranch = $this->branch($businessId, 'Branch Alpha', 'BA');
        $otherBranch = $this->branch($businessId, 'Branch Beta', 'BB');
        $warehouse = $this->warehouse($businessId, $assignedBranch, 'Front Store', 'FS');
        $this->warehouse($businessId, $otherBranch, 'Back Store', 'BS');
        $user->forceFill(['branch_id' => $assignedBranch])->save();

        DB::table('sales_vouchers')->insert([
            $this->sale($businessId, $assignedBranch, $warehouse, $user->id, 'INV-A', 1200),
            $this->sale($businessId, $otherBranch, null, $user->id, 'INV-B', 5000),
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/staff/workspace')
            ->assertOk()
            ->assertSee('Branch Alpha')
            ->assertSee('Front Store')
            ->assertSee('1200')
            ->assertDontSee('Branch Beta')
            ->assertDontSee('5000');
    }

    public function test_invalid_branch_filter_is_rejected(): void
    {
        [$businessId, $user] = $this->businessUser(3);
        $assignedBranch = $this->branch($businessId, 'Branch Alpha', 'BA');
        $otherBranch = $this->branch($businessId, 'Branch Beta', 'BB');
        $user->forceFill(['branch_id' => $assignedBranch])->save();

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/staff/workspace?branch_id=' . $otherBranch)
            ->assertForbidden();
    }

    public function test_staff_quick_actions_do_not_include_admin_actions(): void
    {
        [$businessId, $user] = $this->businessUser(3);
        $branch = $this->branch($businessId, 'Branch Alpha', 'BA');
        $this->warehouse($businessId, $branch, 'Front Store', 'FS');
        $user->forceFill(['branch_id' => $branch])->save();

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/staff/workspace')
            ->assertOk()
            ->assertSee('New Sale')
            ->assertDontSee('Open Masters')
            ->assertDontSee('Employees')
            ->assertDontSee('Users & Roles')
            ->assertDontSee('SaaS Admin')
            ->assertDontSee('Settings');
    }

    public function test_recent_activity_is_scoped_to_logged_in_staff_member(): void
    {
        [$businessId, $user] = $this->businessUser(3);
        $otherUser = User::factory()->create(['tenant_id' => $businessId, 'role_id' => 3, 'is_active' => 1, 'status' => 'active']);

        DB::table('audit_logs')->insert([
            ['client_id' => $businessId, 'module_name' => 'sales', 'record_id' => '1', 'action_type' => 'created', 'summary' => 'My sale saved', 'changed_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            ['client_id' => $businessId, 'module_name' => 'sales', 'record_id' => '2', 'action_type' => 'created', 'summary' => 'Other sale saved', 'changed_by_user_id' => $otherUser->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/staff/workspace')
            ->assertOk()
            ->assertSee('My sale saved')
            ->assertDontSee('Other sale saved');
    }

    public function test_staff_tasks_and_attendance_routes_render_workspace(): void
    {
        [$businessId, $user] = $this->businessUser(3);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/staff/tasks')
            ->assertOk()
            ->assertSee('Staff Workspace');

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->get('/app/staff/attendance')
            ->assertOk()
            ->assertSee('Staff Workspace');
    }

    public function test_logout_is_post_route(): void
    {
        [$businessId, $user] = $this->businessUser(3);

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

    private function branch(int $businessId, string $name, string $code): int
    {
        return DB::table('branches')->insertGetId([
            'business_id' => $businessId,
            'name' => $name,
            'code' => $code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(int $businessId, int $branchId, string $name, string $code): int
    {
        return DB::table('warehouses')->insertGetId([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'name' => $name,
            'code' => $code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sale(int $businessId, int $branchId, ?int $warehouseId, int $userId, string $invoiceNumber, int $total): array
    {
        return [
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'voucher_number' => 'SV-' . $invoiceNumber,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => now()->toDateString(),
            'sale_type' => 'cash',
            'invoice_type' => 'tax_invoice',
            'tax_type' => 'intrastate',
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_amount' => $total,
            'balance_amount' => 0,
            'payment_status' => 'paid',
            'status' => 'approved',
            'salesperson_id' => $userId,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
