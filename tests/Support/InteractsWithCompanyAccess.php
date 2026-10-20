<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait InteractsWithCompanyAccess
{
    protected function createAccessibleUser(int $companyId, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'tenant_id' => $companyId,
            'role_id' => 2,
            'is_active' => 1,
            'status' => 'active',
        ], $attributes));

        $this->grantCompanyAccess($user, $companyId, $attributes['access_role'] ?? 'member');

        return $user->fresh();
    }

    protected function grantCompanyAccess(User $user, int $companyId, string $role = 'member', bool $default = true): void
    {
        if (Schema::hasColumn('users', 'tenant_id')) {
            $user->forceFill(['tenant_id' => $companyId])->save();
        }

        if (!Schema::hasTable('company_user_access')) {
            return;
        }

        DB::table('company_user_access')->updateOrInsert(
            ['company_id' => $companyId, 'user_id' => $user->id],
            [
                'access_role' => $role,
                'is_default' => $default,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
