<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\StockCountSession;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

class InventoryFreezeService
{
    private const BLOCKING_STATUSES = ['draft', 'assigned', 'counting', 'submitted', 'reviewed', 'approved', 'in_progress'];

    public function assertWarehouseAvailable(?int $branchId, ?int $warehouseId, ?int $businessId = null): void
    {
        if (!$warehouseId) {
            return;
        }

        $businessId = $businessId ?: AppController::businessId();
        $count = StockCountSession::query()
            ->where('business_id', $businessId)
            ->where('warehouse_id', $warehouseId)
            ->where('freeze_stock', true)
            ->whereIn('status', self::BLOCKING_STATUSES)
            ->when($branchId !== null, fn ($query) => $query->where(function ($branch) use ($branchId) {
                $branch->whereNull('branch_id')->orWhere('branch_id', $branchId);
            }))
            ->with('warehouse')
            ->orderByDesc('id')
            ->first();

        if (!$count) {
            return;
        }

        $warehouseName = $count->warehouse?->name
            ?: Warehouse::query()->where('id', $warehouseId)->value('name')
            ?: 'Selected warehouse';

        throw ValidationException::withMessages([
            'warehouse_id' => "Stock movement blocked. {$warehouseName} is currently frozen due to active stock count {$count->session_number}. Complete or cancel the stock count before posting inventory movements.",
        ]);
    }

    public function assertWarehousesAvailable(array $warehouses, ?int $businessId = null): void
    {
        foreach ($warehouses as $warehouse) {
            $this->assertWarehouseAvailable(
                $warehouse['branch_id'] ?? null,
                $warehouse['warehouse_id'] ?? null,
                $businessId
            );
        }
    }
}
