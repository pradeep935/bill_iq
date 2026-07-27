<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\HsnMaster;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class MasterDataService
{
    public function references(array $only = []): array
    {
        $businessId = AppController::businessId();
        $this->ensureDefaultWarehouses($businessId);

        $all = [
            'branches' => fn () => $this->branches($businessId),
            'warehouses' => fn () => $this->warehouses($businessId),
            'categories' => fn () => $this->categories($businessId, false),
            'sub_categories' => fn () => $this->categories($businessId, true),
            'brands' => fn () => $this->brands($businessId),
            'units' => fn () => $this->units(),
            'hsn_codes' => fn () => $this->hsnCodes(),
        ];

        $keys = $only ?: array_keys($all);
        $references = [];

        foreach ($keys as $key) {
            if (isset($all[$key])) {
                $references[$key] = $all[$key]();
            }
        }

        return $references;
    }

    public function branches(int $businessId)
    {
        return Branch::query()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get($this->columns('branches', ['id', 'name', 'code', 'type', 'city', 'state']));
    }

    public function warehouses(int $businessId)
    {
        return Warehouse::query()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get($this->columns('warehouses', ['id', 'branch_id', 'name', 'code']));
    }

    public function categories(int $businessId, bool $subCategories = false)
    {
        return ProductCategory::query()
            ->where(function (Builder $query) use ($businessId) {
                $query->whereNull('business_id')->orWhere('business_id', $businessId);
            })
            ->when(Schema::hasColumn('product_categories', 'parent_id'), function (Builder $query) use ($subCategories) {
                $subCategories ? $query->whereNotNull('parent_id') : $query->whereNull('parent_id');
            })
            ->when(Schema::hasColumn('product_categories', 'status'), fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->get($this->columns('product_categories', ['id', 'parent_id', 'name', 'code']));
    }

    public function brands(int $businessId)
    {
        return Brand::query()
            ->where(function (Builder $query) use ($businessId) {
                $query->whereNull('business_id')->orWhere('business_id', $businessId);
            })
            ->when(Schema::hasColumn('brands', 'status'), fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->get($this->columns('brands', ['id', 'name', 'code']));
    }

    public function units()
    {
        return Unit::query()
            ->when(Schema::hasColumn('units', 'status'), fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy(Schema::hasColumn('units', 'code') ? 'code' : 'name')
            ->get($this->columns('units', ['id', 'code', 'name', 'symbol']));
    }

    public function hsnCodes()
    {
        return HsnMaster::query()
            ->when(Schema::hasColumn('hsn_masters', 'status'), fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('hsn_code')
            ->get($this->columns('hsn_masters', ['id', 'hsn_code', 'description', 'gst_rate', 'cess_rate']));
    }

    public function ensureDefaultWarehouses(int $businessId): void
    {
        foreach ($this->branches($businessId) as $branch) {
            $exists = Warehouse::query()
                ->where('business_id', $businessId)
                ->where('branch_id', $branch->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $payload = [
                'business_id' => $businessId,
                'branch_id' => $branch->id,
                'name' => strtolower((string) ($branch->type ?? '')) === 'warehouse' ? $branch->name : 'Default warehouse',
                'status' => 'active',
            ];

            if (Schema::hasColumn('warehouses', 'code')) {
                $payload['code'] = 'WH-' . $branch->id;
            }

            Warehouse::query()->create($payload);
        }
    }

    public function columns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
    }
}
