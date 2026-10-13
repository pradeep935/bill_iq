<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CompanyOnboardingService
{
    private CompanyAccessService $access;

    public function __construct(CompanyAccessService $access)
    {
        $this->access = $access;
    }

    public function create(array $data, User $creator): array
    {
        return DB::transaction(function () use ($data, $creator) {
            $gstin = $this->normalizeGstin($data['gstin'] ?? null);
            $this->assertUniqueGstin($gstin);

            $company = Company::query()->create($this->companyPayload($data, $gstin));
            $period = $this->createAccountingPeriod($company, $data);
            $branch = $this->createBranch($company, $data);
            $warehouse = $this->createWarehouse($company, $branch->id, $data);

            $authorizedUserIds = collect($data['authorized_user_ids'] ?? [])
                ->push($creator->id)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            foreach ($authorizedUserIds as $userId) {
                User::query()->whereKey($userId)->firstOrFail();
                $this->access->grant($userId, $company->id, $userId === (int) $creator->id ? 'owner' : 'admin', $userId === (int) $creator->id, $creator->id);
            }

            if (Schema::hasColumn('users', 'tenant_id') && !$creator->tenant_id) {
                $creator->forceFill(['tenant_id' => $company->id])->save();
            }

            AuditLogger::record([
                'module_name' => 'Company',
                'record_id' => $company->id,
                'action_type' => 'Create',
                'business_id' => $company->id,
                'summary' => 'Company onboarded',
            ]);

            return [
                'company' => $company->fresh(),
                'accounting_period' => $period,
                'branch' => $branch,
                'warehouse' => $warehouse,
            ];
        });
    }

    private function companyPayload(array $data, ?string $gstin): array
    {
        $payload = [
            'name' => trim((string) $data['legal_name']),
            'gstin' => $gstin,
            'state' => trim((string) $data['state']),
            'financial_year' => trim((string) $data['financial_year']),
        ];

        return collect($payload)
            ->filter(fn ($value, $key) => Schema::hasColumn('companies', $key))
            ->all();
    }

    private function createAccountingPeriod(Company $company, array $data): ?object
    {
        if (!Schema::hasTable('accounting_periods')) {
            return null;
        }

        [$start, $end] = $this->periodDates($data);

        DB::table('accounting_periods')->updateOrInsert(
            ['business_id' => $company->id, 'financial_year' => $data['financial_year']],
            [
                'start_date' => $start,
                'end_date' => $end,
                'status' => $data['accounting_period_status'] ?? 'open',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return DB::table('accounting_periods')
            ->where('business_id', $company->id)
            ->where('financial_year', $data['financial_year'])
            ->first();
    }

    private function createBranch(Company $company, array $data): object
    {
        $payload = [
            'business_id' => $company->id,
            'tenant_id' => $company->id,
            'name' => trim((string) $data['default_branch_name']),
            'code' => $data['default_branch_code'] ?? 'MAIN',
            'state' => trim((string) $data['state']),
            'city' => $data['default_branch_city'] ?? null,
            'address' => $data['default_branch_address'] ?? $data['registered_address'] ?? null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $payload = collect($payload)->filter(fn ($value, $key) => Schema::hasColumn('branches', $key))->all();
        $id = DB::table('branches')->insertGetId($payload);

        return DB::table('branches')->where('id', $id)->first();
    }

    private function createWarehouse(Company $company, int $branchId, array $data): object
    {
        $payload = [
            'business_id' => $company->id,
            'tenant_id' => $company->id,
            'branch_id' => $branchId,
            'name' => trim((string) $data['default_warehouse_name']),
            'code' => $data['default_warehouse_code'] ?? 'MAIN',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $payload = collect($payload)->filter(fn ($value, $key) => Schema::hasColumn('warehouses', $key))->all();
        $id = DB::table('warehouses')->insertGetId($payload);

        return DB::table('warehouses')->where('id', $id)->first();
    }

    private function normalizeGstin(?string $gstin): ?string
    {
        $gstin = strtoupper(trim((string) $gstin));
        return $gstin === '' ? null : $gstin;
    }

    private function assertUniqueGstin(?string $gstin): void
    {
        if (!$gstin || !Schema::hasColumn('companies', 'gstin')) {
            return;
        }

        $exists = Company::query()
            ->whereRaw('UPPER(gstin) = ?', [$gstin])
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['gstin' => 'A company with this GSTIN already exists. Review the existing company before creating another one.']);
        }
    }

    private function periodDates(array $data): array
    {
        if (!empty($data['period_start_date']) && !empty($data['period_end_date'])) {
            return [$data['period_start_date'], $data['period_end_date']];
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', (string) $data['financial_year'], $matches)) {
            $startYear = (int) $matches[1];
            $endYear = $startYear + 1;

            return [$startYear . '-04-01', $endYear . '-03-31'];
        }

        return [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()];
    }
}
