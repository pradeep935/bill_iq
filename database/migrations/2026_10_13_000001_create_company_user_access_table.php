<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_user_access')) {
            Schema::create('company_user_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('access_role', 30)->default('member');
                $table->boolean('is_default')->default(false);
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['company_id', 'user_id'], 'company_user_access_unique');
                $table->index(['user_id', 'status'], 'company_user_access_user_status_index');
            });
        }

        if (!Schema::hasTable('users') || !Schema::hasTable('companies')) {
            return;
        }

        DB::transaction(function () {
            $now = now();
            $created = 0;

            if (Schema::hasColumn('users', 'tenant_id')) {
                DB::table('users')
                    ->whereNotNull('tenant_id')
                    ->orderBy('id')
                    ->get(['id', 'tenant_id', 'role_id'])
                    ->each(function ($user) use ($now, &$created) {
                        if (!DB::table('companies')->where('id', $user->tenant_id)->exists()) {
                            return;
                        }

                        $this->grantCompanyAccess(
                            (int) $user->id,
                            (int) $user->tenant_id,
                            $this->accessRole($user->role_id ?? null),
                            true,
                            $now
                        );
                        $created++;
                    });
            }

            if ($created > 0 || DB::table('company_user_access')->where('status', 'active')->exists()) {
                return;
            }

            $companyIds = DB::table('companies')->orderBy('id')->limit(2)->pluck('id');

            if ($companyIds->count() !== 1) {
                return;
            }

            $companyId = (int) $companyIds->first();
            $userQuery = DB::table('users')->orderBy('id');

            if (Schema::hasColumn('users', 'status')) {
                $userQuery->where(function ($query) {
                    $query->whereNull('status')->orWhere('status', 'active');
                });
            }

            if (Schema::hasColumn('users', 'is_active')) {
                $userQuery->where(function ($query) {
                    $query->whereNull('is_active')->orWhere('is_active', true);
                });
            }

            $columns = ['id'];
            if (Schema::hasColumn('users', 'role_id')) {
                $columns[] = 'role_id';
            }

            $userQuery->get($columns)->each(function ($user) use ($companyId, $now) {
                $this->grantCompanyAccess(
                    (int) $user->id,
                    $companyId,
                    $this->accessRole($user->role_id ?? null),
                    true,
                    $now
                );
            });
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user_access');
    }

    private function grantCompanyAccess(int $userId, int $companyId, string $role, bool $default, $timestamp): void
    {
        DB::table('company_user_access')->updateOrInsert(
            ['company_id' => $companyId, 'user_id' => $userId],
            [
                'access_role' => $role,
                'is_default' => $default,
                'status' => 'active',
                'updated_at' => $timestamp,
                'created_at' => $timestamp,
            ]
        );
    }

    private function accessRole($roleId): string
    {
        return ((int) ($roleId ?? 0)) === 1 ? 'owner' : 'admin';
    }
};
