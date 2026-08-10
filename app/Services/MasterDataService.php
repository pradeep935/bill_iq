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
            'gst_rate_slabs' => fn () => $this->gstRateSlabs(),
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
        $nameColumn = Schema::hasColumn('product_categories', 'category_name') ? 'category_name' : 'name';

        return ProductCategory::query()
            ->when(
                Schema::hasColumn('product_categories', 'business_id'),
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('business_id')->orWhere('business_id', $businessId))
            )
            ->when(
                !Schema::hasColumn('product_categories', 'business_id') && Schema::hasColumn('product_categories', 'company_id'),
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('company_id')->orWhere('company_id', $businessId))
            )
            ->when(Schema::hasColumn('product_categories', 'parent_id'), function (Builder $query) use ($subCategories) {
                $subCategories ? $query->whereNotNull('parent_id') : $query->whereNull('parent_id');
            })
            ->when(Schema::hasColumn('product_categories', 'status'), fn (Builder $query) => $query->whereRaw('LOWER(status) = ?', ['active']))
            ->orderBy($nameColumn)
            ->get($this->columns('product_categories', ['id', 'parent_id', $nameColumn, 'code']))
            ->map(function (ProductCategory $category) use ($nameColumn) {
                $label = $category->{$nameColumn} ?? $category->name ?? $category->category_name ?? '';

                return [
                    'id' => $category->id,
                    'value' => (string) $category->id,
                    'label' => $label,
                    'name' => $label,
                    'parent_id' => $category->parent_id ?? null,
                    'code' => $category->code ?? null,
                ];
            })
            ->values();
    }

    public function brands(int $businessId)
    {
        return Brand::query()
            ->where(function (Builder $query) use ($businessId) {
                $query->whereNull('business_id')->orWhere('business_id', $businessId);
            })
            ->when(Schema::hasColumn('brands', 'status'), fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('name')
            ->get($this->columns('brands', ['id', 'name', 'code']))
            ->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'value' => (string) $brand->id,
                'label' => $brand->name,
                'name' => $brand->name,
                'code' => $brand->code ?? null,
            ])
            ->values();
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
        $businessId = AppController::businessId();

        return HsnMaster::query()
            ->where(function (Builder $query) use ($businessId) {
                $query->where(fn (Builder $scope) => $scope->whereNull('business_id')->orWhere('business_id', $businessId));
            })
            ->where('status', 'active')
            ->where('verification_status', 'verified')
            ->where(function (Builder $query) {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now()->toDateString());
            })
            ->where(function (Builder $query) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString());
            })
            ->orderBy('hsn_code')
            ->limit(100)
            ->get($this->columns('hsn_masters', ['id', 'hsn_code', 'code_type', 'description', 'taxability', 'classification_verified', 'rate_verified', 'gst_rate', 'cess_rate', 'effective_from', 'effective_to']));
    }

    public function gstRateSlabs()
    {
        if (!Schema::hasTable('gst_rate_slabs')) {
            return collect();
        }

        return \DB::table('gst_rate_slabs')
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', 'active')->orWhere('status', 1);
            })
            ->when(Schema::hasColumn('gst_rate_slabs', 'selectable'), fn ($query) => $query->where('selectable', 1))
            ->orderByDesc(Schema::hasColumn('gst_rate_slabs', 'is_common') ? 'is_common' : 'rate')
            ->orderBy(Schema::hasColumn('gst_rate_slabs', 'sort_order') ? 'sort_order' : 'rate')
            ->get($this->columns('gst_rate_slabs', ['id', 'rate', 'label', 'is_common', 'selectable', 'notes']))
            ->map(fn ($slab) => [
                'id' => $slab->id,
                'value' => (string) (float) $slab->rate,
                'label' => $slab->label ?: rtrim(rtrim((string) $slab->rate, '0'), '.') . '%',
                'is_common' => (bool) ($slab->is_common ?? false),
                'selectable' => (bool) ($slab->selectable ?? true),
                'notes' => $slab->notes ?? null,
            ])
            ->values();
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
