<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** A batch is a product lot; its location and condition balances live only in stock_ledgers. */
class BatchIdentityService
{
    public function resolve(int $businessId, int $productId, array $data): ProductBatch
    {
        return DB::transaction(function () use ($businessId, $productId, $data) {
            // Serialize lot creation with all other stock postings for this product.
            Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $number = trim($data['batch_number'] ?? $data['batch_no'] ?? '');
            $mfg = empty($data['manufacturing_date']) ? null : Carbon::parse($data['manufacturing_date'])->toDateString();
            $expiry = empty($data['expiry_date']) ? null : Carbon::parse($data['expiry_date'])->toDateString();
            if ($mfg && $expiry && $expiry < $mfg) {
                throw ValidationException::withMessages(['expiry_date' => 'Expiry cannot be before manufacturing date.']);
            }
            $query = ProductBatch::query()->where('business_id', $businessId)->where('product_id', $productId);
            if (! empty($data['batch_id'])) {
                $query->whereKey($data['batch_id']);
            } else {
                if ($number === '') {
                    throw ValidationException::withMessages(['batch_number' => 'Batch number is required.']);
                }
                $query->where(fn ($q) => $q->where('batch_no', $number)->orWhere('batch_number', $number));
            }
            $batch = $query->orderBy('id')->lockForUpdate()->first();
            if ($batch) {
                foreach (['manufacturing_date' => $mfg, 'expiry_date' => $expiry] as $field => $value) {
                    $existing = $batch->{$field} ?: ($field === 'manufacturing_date' ? $batch->mfg_date : null);
                    if ($value && (! $existing || Carbon::parse($existing)->toDateString() !== $value)) {
                        throw ValidationException::withMessages([$field => 'This lot already exists with different dates. Select the existing lot or use a different batch number.']);
                    }
                }

                return $batch;
            }
            if (! empty($data['batch_id'])) {
                throw ValidationException::withMessages(['batch_id' => 'Select a batch belonging to this product.']);
            }
            $payload = [
                'business_id' => $businessId, 'tenant_id' => $businessId, 'product_id' => $productId,
                'batch_no' => $number, 'batch_number' => $number, 'manufacturing_date' => $mfg,
                'mfg_date' => $mfg, 'expiry_date' => $expiry, 'status' => 'active', 'condition_status' => 'saleable',
                'purchase_price' => $data['unit_cost'] ?? 0, 'cost_price' => $data['unit_cost'] ?? 0,
                'quantity' => 0,
            ];

            return ProductBatch::query()->create(array_filter($payload, fn ($v, $k) => Schema::hasColumn('product_batches', $k), ARRAY_FILTER_USE_BOTH));
        });
    }
}
