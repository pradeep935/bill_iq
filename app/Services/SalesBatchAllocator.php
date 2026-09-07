<?php

namespace App\Services;

use App\Models\ProductBatch;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SalesBatchAllocator
{
    public function __construct(private StockService $stock) {}

    public function eligible(array $scope, bool $lock = false, ?callable $reservationCredit = null): Collection
    {
        $query = ProductBatch::query()
            ->where('business_id', $scope['business_id'])
            ->where('product_id', $scope['product_id'])
            ->when(!empty($scope['batch_id']), fn ($q) => $q->whereKey($scope['batch_id']))
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('condition_status')->orWhere('condition_status', 'saleable'))
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->map(function ($batch) use ($scope, $lock, $reservationCredit) {
            $batchScope = array_merge($scope, ['batch_id' => $batch->id]);
            return [
                'id' => $batch->id,
                'batch_no' => $batch->batch_no ?: $batch->batch_number,
                'expiry_date' => optional($batch->expiry_date)->format('Y-m-d'),
                'available_stock' => max(0, $this->stock->getAvailableToSell($batchScope, null, $lock) + ($reservationCredit ? $reservationCredit($batchScope) : 0)),
            ];
        })->filter(fn ($batch) => $batch['available_stock'] > 0)->values();
    }

    public function allocate(array $scope, float $quantity, bool $lock = false, ?callable $reservationCredit = null): array
    {
        $allocations = [];
        foreach ($this->eligible($scope, $lock, $reservationCredit) as $batch) {
            $take = min($quantity, $batch['available_stock']);
            if ($take <= 0) break;
            $allocations[] = ['batch_id' => $batch['id'], 'quantity' => $take];
            $quantity = round($quantity - $take, 3);
        }
        if ($quantity > 0) {
            throw ValidationException::withMessages(['quantity' => 'Insufficient eligible batch stock at the selected branch and warehouse. Refresh stock and try again.']);
        }
        return $allocations;
    }
}
