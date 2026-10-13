<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_switch_only_to_accessible_company(): void
    {
        [$abcId, $user] = $this->businessUser('ABC Retail Pvt Ltd');
        $clientId = $this->company('Verified Client Ltd', '09ABCDE1234F1Z5');
        $this->grant($user->id, $clientId);

        $this->actingAs($user)
            ->withSession(['business_id' => $abcId])
            ->post('/app/context', ['business_id' => $clientId])
            ->assertRedirect();

        $this->assertSame($clientId, session('business_id'));
    }

    public function test_unauthorized_company_switch_is_forbidden(): void
    {
        [$abcId, $user] = $this->businessUser('ABC Retail Pvt Ltd');
        $otherId = $this->company('Other Business Ltd', '09ABCDE1234F1Z5');

        $this->actingAs($user)
            ->withSession(['business_id' => $abcId])
            ->post('/app/context', ['business_id' => $otherId])
            ->assertForbidden();
    }

    public function test_forged_session_does_not_expose_other_company_records(): void
    {
        [$abcId, $user] = $this->businessUser('ABC Retail Pvt Ltd');
        $otherId = $this->company('Other Business Ltd', '09ABCDE1234F1Z5');
        $otherCustomerId = $this->customer($otherId, 'Other Customer');

        $this->actingAs($user)
            ->withSession(['business_id' => $otherId])
            ->get("/app/sales/customers/{$otherCustomerId}")
            ->assertNotFound();

        $this->assertSame(1, DB::table('companies')->where('id', $abcId)->count());
    }

    public function test_company_scoped_customer_list_excludes_other_company_data(): void
    {
        [$abcId, $user] = $this->businessUser('ABC Retail Pvt Ltd');
        $this->customer($abcId, 'ABC Buyer');
        $otherId = $this->company('Other Business Ltd', '09ABCDE1234F1Z5');
        $this->customer($otherId, 'Other Buyer');

        $this->actingAs($user)
            ->withSession(['business_id' => $abcId])
            ->get('/app/sales/customers/list')
            ->assertOk()
            ->assertJsonFragment(['customer_name' => 'ABC Buyer'])
            ->assertJsonMissing(['customer_name' => 'Other Buyer']);
    }

    public function test_context_switch_rejects_branch_from_another_company(): void
    {
        [$abcId, $user] = $this->businessUser('ABC Retail Pvt Ltd');
        $clientId = $this->company('Verified Client Ltd', '09ABCDE1234F1Z5');
        $this->grant($user->id, $clientId);
        $abcBranchId = $this->branch($abcId, 'ABC Branch');

        $this->actingAs($user)
            ->withSession(['business_id' => $abcId])
            ->post('/app/context', ['business_id' => $clientId, 'branch_id' => $abcBranchId])
            ->assertStatus(422);
    }

    public function test_company_onboarding_creates_company_period_branch_warehouse_and_access_transactionally(): void
    {
        [$abcId, $user] = $this->businessUser('ABC Retail Pvt Ltd');
        $extraUser = User::factory()->create(['role_id' => 2, 'status' => 'active', 'is_active' => 1]);

        $response = $this->actingAs($user)
            ->withSession(['business_id' => $abcId])
            ->postJson('/app/setup/companies', [
                'legal_name' => 'Verified Client Limited',
                'gstin' => '09ABCDE1234F1Z5',
                'state' => 'Uttar Pradesh',
                'financial_year' => '2026-27',
                'default_branch_name' => 'Head Office',
                'default_branch_code' => 'HO',
                'default_warehouse_name' => 'Main Warehouse',
                'default_warehouse_code' => 'MW',
                'authorized_user_ids' => [$extraUser->id],
            ]);

        $response->assertCreated()->assertJsonFragment(['name' => 'Verified Client Limited']);

        $companyId = (int) DB::table('companies')->where('gstin', '09ABCDE1234F1Z5')->value('id');
        $this->assertGreaterThan(0, $companyId);
        $this->assertDatabaseHas('accounting_periods', ['business_id' => $companyId, 'financial_year' => '2026-27']);
        $this->assertDatabaseHas('branches', ['business_id' => $companyId, 'name' => 'Head Office']);
        $this->assertDatabaseHas('warehouses', ['business_id' => $companyId, 'name' => 'Main Warehouse']);
        $this->assertDatabaseHas('company_user_access', ['company_id' => $companyId, 'user_id' => $user->id, 'status' => 'active']);
        $this->assertDatabaseHas('company_user_access', ['company_id' => $companyId, 'user_id' => $extraUser->id, 'status' => 'active']);
    }

    public function test_company_onboarding_rejects_duplicate_gstin_without_creating_children(): void
    {
        [$abcId, $user] = $this->businessUser('ABC Retail Pvt Ltd', '09ABCDE1234F1Z5');
        $beforeCompanies = DB::table('companies')->count();

        $this->actingAs($user)
            ->withSession(['business_id' => $abcId])
            ->postJson('/app/setup/companies', [
                'legal_name' => 'Duplicate GSTIN Ltd',
                'gstin' => '09ABCDE1234F1Z5',
                'state' => 'Uttar Pradesh',
                'financial_year' => '2026-27',
                'default_branch_name' => 'Head Office',
                'default_warehouse_name' => 'Main Warehouse',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gstin']);

        $this->assertSame($beforeCompanies, DB::table('companies')->count());
        $this->assertDatabaseMissing('branches', ['name' => 'Head Office']);
        $this->assertDatabaseMissing('warehouses', ['name' => 'Main Warehouse']);
    }

    public function test_authenticated_request_without_company_access_does_not_create_fallback_company(): void
    {
        $user = User::factory()->create(['tenant_id' => null, 'role_id' => 2, 'status' => 'active', 'is_active' => 1]);

        $this->actingAs($user)
            ->get('/app')
            ->assertForbidden();

        $this->assertSame(0, DB::table('companies')->count());
    }

    public function test_legacy_single_company_migration_grants_active_existing_users(): void
    {
        $companyId = $this->company('ABC Retail Pvt Ltd');
        $owner = User::factory()->create(['tenant_id' => null, 'role_id' => 1, 'status' => 'active', 'is_active' => 1]);
        $admin = User::factory()->create(['tenant_id' => null, 'role_id' => 2, 'status' => 'active', 'is_active' => 1]);
        $inactive = User::factory()->create(['tenant_id' => null, 'role_id' => 2, 'status' => 'inactive', 'is_active' => 0]);

        $this->runCompanyAccessMigration();

        $this->assertDatabaseHas('company_user_access', [
            'company_id' => $companyId,
            'user_id' => $owner->id,
            'access_role' => 'owner',
            'is_default' => true,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('company_user_access', [
            'company_id' => $companyId,
            'user_id' => $admin->id,
            'access_role' => 'admin',
            'is_default' => true,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('company_user_access', [
            'company_id' => $companyId,
            'user_id' => $inactive->id,
        ]);
    }

    public function test_legacy_access_migration_does_not_guess_access_when_multiple_companies_exist(): void
    {
        $this->company('ABC Retail Pvt Ltd');
        $this->company('Other Business Ltd', '09ABCDE1234F1Z5');
        User::factory()->create(['tenant_id' => null, 'role_id' => 2, 'status' => 'active', 'is_active' => 1]);

        $this->runCompanyAccessMigration();

        $this->assertSame(0, DB::table('company_user_access')->count());
    }

    public function test_legacy_access_migration_prefers_explicit_tenant_mappings(): void
    {
        $abcId = $this->company('ABC Retail Pvt Ltd');
        $clientId = $this->company('Verified Client Ltd', '09ABCDE1234F1Z5');
        $abcUser = User::factory()->create(['tenant_id' => $abcId, 'role_id' => 2, 'status' => 'active', 'is_active' => 1]);
        $clientUser = User::factory()->create(['tenant_id' => $clientId, 'role_id' => 2, 'status' => 'active', 'is_active' => 1]);
        User::factory()->create(['tenant_id' => null, 'role_id' => 2, 'status' => 'active', 'is_active' => 1]);

        $this->runCompanyAccessMigration();

        $this->assertDatabaseHas('company_user_access', ['company_id' => $abcId, 'user_id' => $abcUser->id]);
        $this->assertDatabaseHas('company_user_access', ['company_id' => $clientId, 'user_id' => $clientUser->id]);
        $this->assertSame(2, DB::table('company_user_access')->count());
    }

    public function test_legacy_access_migration_is_repeat_safe(): void
    {
        $companyId = $this->company('ABC Retail Pvt Ltd');
        $user = User::factory()->create(['tenant_id' => null, 'role_id' => 2, 'status' => 'active', 'is_active' => 1]);

        $this->runCompanyAccessMigration();
        $this->runCompanyAccessMigration();

        $this->assertSame(1, DB::table('company_user_access')->count());
        $this->assertDatabaseHas('company_user_access', ['company_id' => $companyId, 'user_id' => $user->id]);
    }

    private function businessUser(string $name, ?string $gstin = null): array
    {
        $businessId = $this->company($name, $gstin);
        $user = User::factory()->create([
            'tenant_id' => $businessId,
            'role_id' => 2,
            'is_active' => 1,
            'status' => 'active',
        ]);
        $this->grant($user->id, $businessId, true);

        return [$businessId, $user];
    }

    private function company(string $name, ?string $gstin = null): int
    {
        return DB::table('companies')->insertGetId([
            'name' => $name,
            'gstin' => $gstin,
            'state' => 'UP',
            'financial_year' => '2026-27',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function grant(int $userId, int $companyId, bool $default = false): void
    {
        DB::table('company_user_access')->updateOrInsert(
            ['company_id' => $companyId, 'user_id' => $userId],
            [
                'access_role' => 'admin',
                'is_default' => $default,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function branch(int $businessId, string $name): int
    {
        return DB::table('branches')->insertGetId([
            'business_id' => $businessId,
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $businessId, string $name): int
    {
        $payload = [
            'business_id' => $businessId,
            'company_id' => $businessId,
            'customer_code' => 'CUS-' . $businessId . '-' . uniqid(),
            'customer_name' => $name,
            'name' => $name,
            'customer_type' => 'retail',
            'mobile' => '999999' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
            'opening_balance' => 0,
            'opening_balance_type' => 'debit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $columns = Schema::getColumnListing('customers');

        return DB::table('customers')->insertGetId(array_intersect_key($payload, array_flip($columns)));
    }

    private function runCompanyAccessMigration(): void
    {
        $migration = require database_path('migrations/2026_10_13_000001_create_company_user_access_table.php');
        $migration->up();
    }
}
