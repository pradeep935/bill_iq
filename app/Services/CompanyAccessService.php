<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompanyAccessService
{
    public function accessibleCompanies(User $user): Collection
    {
        if (!Schema::hasTable('companies')) {
            return collect();
        }

        if (Schema::hasTable('company_user_access')) {
            $companies = Company::query()
                ->select('companies.*')
                ->join('company_user_access', 'company_user_access.company_id', '=', 'companies.id')
                ->where('company_user_access.user_id', $user->id)
                ->where('company_user_access.status', 'active')
                ->orderByDesc('company_user_access.is_default')
                ->orderBy('companies.name')
                ->get();

            if ($companies->isNotEmpty()) {
                return $companies;
            }
        }

        if ($user->tenant_id) {
            $company = Company::query()->whereKey($user->tenant_id)->first();
            return $company ? collect([$company]) : collect();
        }

        return collect();
    }

    public function canAccess(User $user, int $companyId): bool
    {
        return $this->accessibleCompanies($user)->contains(fn (Company $company) => (int) $company->id === $companyId);
    }

    public function defaultCompanyId(User $user): ?int
    {
        if (!Schema::hasTable('companies')) {
            return null;
        }

        if (Schema::hasTable('company_user_access')) {
            $defaultId = DB::table('company_user_access')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('company_id');

            if ($defaultId && DB::table('companies')->where('id', $defaultId)->exists()) {
                return (int) $defaultId;
            }
        }

        if ($user->tenant_id && DB::table('companies')->where('id', $user->tenant_id)->exists()) {
            return (int) $user->tenant_id;
        }

        return null;
    }

    public function grant($user, int $companyId, string $role = 'member', bool $default = false, ?int $createdBy = null): void
    {
        if (!Schema::hasTable('company_user_access')) {
            return;
        }

        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        DB::transaction(function () use ($userId, $companyId, $role, $default, $createdBy) {
            if ($default) {
                DB::table('company_user_access')->where('user_id', $userId)->update(['is_default' => false, 'updated_at' => now()]);
            }

            DB::table('company_user_access')->updateOrInsert(
                ['company_id' => $companyId, 'user_id' => $userId],
                [
                    'access_role' => $role,
                    'is_default' => $default,
                    'status' => 'active',
                    'created_by' => $createdBy,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        });
    }
}
