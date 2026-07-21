<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\OpeningStockVoucher;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariantItem;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningStockService
{
    private StockService $stock;

    public function __construct(StockService $stock)
    {
        $this->stock = $stock;
    }

    public function list(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return OpeningStockVoucher::query()
            ->with(['branch', 'warehouse', 'items.product'])
            ->where('business_id', $businessId)
            ->when(!empty($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): OpeningStockVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $status = $data['status'] ?? 'draft';
            $this->validateHeaderOwnership($businessId, $data);

            $voucher = OpeningStockVoucher::query()->create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'voucher_number' => $this->nextVoucherNumber($businessId),
                'opening_date' => $data['opening_date'],
                'remarks' => $data['remarks'] ?? null,
                'status' => $status === 'approved' || $status === 'confirmed' ? 'draft' : $status,
                'created_by' => Auth::id(),
            ]);

            $this->syncItems($voucher, $data['items'] ?? []);

            if (in_array($status, ['confirmed', 'approved'], true)) {
                $this->post($voucher, $status);
            }

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Create',
                'business_id' => $businessId,
                'summary' => 'Opening stock voucher created',
            ]);

            return $voucher->fresh(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch']);
        });
    }

    public function update(OpeningStockVoucher $voucher, array $data): OpeningStockVoucher
    {
        return DB::transaction(function () use ($voucher, $data) {
            if ($voucher->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Only draft opening stock vouchers can be edited.',
                ]);
            }

            $businessId = AppController::businessId();
            $this->validateVoucher($voucher, $businessId);
            $this->validateHeaderOwnership($businessId, $data);

            $voucher->update([
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'opening_date' => $data['opening_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $voucher->items()->delete();
            $this->syncItems($voucher, $data['items'] ?? []);

            if (in_array($data['status'] ?? 'draft', ['confirmed', 'approved'], true)) {
                $this->post($voucher, $data['status']);
            }

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Update',
                'business_id' => $businessId,
                'summary' => 'Opening stock voucher updated',
            ]);

            return $voucher->fresh(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch']);
        });
    }

    public function post(OpeningStockVoucher $voucher, string $status = 'approved'): OpeningStockVoucher
    {
        return DB::transaction(function () use ($voucher, $status) {
            $businessId = AppController::businessId();
            $this->validateVoucher($voucher, $businessId);

            if ($voucher->status === 'approved' || $voucher->status === 'confirmed') {
                throw ValidationException::withMessages([
                    'status' => 'Opening stock is already posted.',
                ]);
            }

            if ($this->hasLedgerPosting($voucher)) {
                throw ValidationException::withMessages([
                    'status' => 'Stock ledger already posted for this voucher.',
                ]);
            }

            $voucher->load('items.product');

            if (!$voucher->items->count()) {
                throw ValidationException::withMessages([
                    'items' => 'At least one opening stock item is required.',
                ]);
            }

            foreach ($voucher->items as $item) {
                $batchId = $item->batch_id;

                if (!$batchId && $this->requiresBatch($item->product) && $item->batch_no) {
                    $batchId = ProductBatch::query()->create([
                        'business_id' => $voucher->business_id,
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'batch_number' => $item->batch_no,
                        'manufacturing_date' => $item->manufacturing_date,
                        'expiry_date' => $item->expiry_date,
                        'purchase_price' => $item->purchase_cost,
                        'cost_price' => $item->purchase_cost,
                        'selling_price' => $item->selling_price,
                        'mrp' => $item->mrp,
                        'quantity' => 0,
                        'status' => 'active',
                    ])->id;

                    $item->update(['batch_id' => $batchId]);
                }

                $this->stock->increaseStock([
                    'business_id' => $voucher->business_id,
                    'branch_id' => $voucher->branch_id,
                    'warehouse_id' => $voucher->warehouse_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'batch_id' => $batchId,
                    'transaction_type' => 'opening_stock',
                    'reference_type' => OpeningStockVoucher::class,
                    'reference_id' => $voucher->id,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->purchase_cost,
                    'transaction_date' => $voucher->opening_date,
                    'remarks' => 'Opening stock ' . $voucher->voucher_number,
                ]);
            }

            $voucher->update([
                'status' => $status === 'confirmed' ? 'confirmed' : 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Post',
                'business_id' => $businessId,
                'summary' => 'Opening stock posted to ledger',
            ]);

            return $voucher->fresh(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch']);
        });
    }

    public function reverse(OpeningStockVoucher $voucher, ?string $remarks = null): OpeningStockVoucher
    {
        return DB::transaction(function () use ($voucher, $remarks) {
            $businessId = AppController::businessId();
            $this->validateVoucher($voucher, $businessId);

            if (!in_array($voucher->status, ['approved', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only posted opening stock can be reversed.',
                ]);
            }

            $this->stock->reverseTransaction(OpeningStockVoucher::class, $voucher->id, $remarks ?: 'Opening stock reversal');

            $voucher->update([
                'status' => 'reversed',
                'remarks' => trim(($voucher->remarks ? $voucher->remarks . "\n" : '') . ($remarks ?: 'Reversed')),
            ]);

            AuditLogger::record([
                'module_name' => 'Opening Stock',
                'record_id' => $voucher->id,
                'action_type' => 'Reverse',
                'business_id' => $businessId,
                'summary' => 'Opening stock reversed through stock ledger',
            ]);

            return $voucher->fresh(['branch', 'warehouse', 'items.product', 'items.variant', 'items.batch']);
        });
    }

    public function references(): array
    {
        $businessId = AppController::businessId();

        return [
            'branches' => Branch::query()
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::query()
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'branch_id', 'name', 'code']),
        ];
    }

    public function searchProducts(string $search)
    {
        $businessId = AppController::businessId();

        return Product::query()
            ->with(['barcodes', 'variantItems'])
            ->where(function (Builder $query) use ($businessId) {
                $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->where('product_type', 'goods')
            ->where('item_type', 'stock')
            ->where('status', 'active')
            ->where(function (Builder $query) use ($search) {
                $query
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('sku', 'like', '%' . $search . '%')
                    ->orWhere('primary_barcode', 'like', '%' . $search . '%')
                    ->orWhereHas('barcodes', fn (Builder $barcodeQuery) => $barcodeQuery->where('barcode', 'like', '%' . $search . '%'));
            })
            ->limit(20)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->primary_barcode ?: $product->barcode,
                'unit' => $product->unit ?: 'PCS',
                'tracking_type' => $product->tracking_type ?: 'none',
                'batch_required' => (bool) $product->batch_required,
                'expiry_required' => (bool) $product->expiry_required,
                'selling_price' => (float) ($product->selling_price ?: $product->sale_price),
                'mrp' => $product->mrp !== null ? (float) $product->mrp : null,
                'variants' => $product->variantItems->map(fn (ProductVariantItem $variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'selling_price' => (float) $variant->selling_price,
                    'mrp' => $variant->mrp !== null ? (float) $variant->mrp : null,
                ])->values(),
            ]);
    }

    private function syncItems(OpeningStockVoucher $voucher, array $items): void
    {
        foreach ($items as $item) {
            $product = $this->validProduct($voucher->business_id, (int) $item['product_id']);
            $variantId = $item['product_variant_id'] ?? null;

            if ($variantId) {
                ProductVariantItem::query()
                    ->where('business_id', $voucher->business_id)
                    ->where('product_id', $product->id)
                    ->where('id', $variantId)
                    ->firstOrFail();
            }

            $voucher->items()->create([
                'business_id' => $voucher->business_id,
                'branch_id' => $voucher->branch_id,
                'warehouse_id' => $voucher->warehouse_id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'batch_id' => $item['batch_id'] ?? null,
                'batch_no' => $item['batch_no'] ?? null,
                'quantity' => $item['quantity'],
                'purchase_cost' => $item['purchase_cost'] ?? 0,
                'unit_cost' => $item['purchase_cost'] ?? 0,
                'stock_value' => (float) $item['quantity'] * (float) ($item['purchase_cost'] ?? 0),
                'selling_price' => $item['selling_price'] ?? 0,
                'mrp' => $item['mrp'] ?? null,
                'warehouse_location' => $item['warehouse_location'] ?? null,
                'manufacturing_date' => $item['manufacturing_date'] ?? null,
                'expiry_date' => $item['expiry_date'] ?? null,
                'remarks' => $item['remarks'] ?? null,
            ]);
        }
    }

    private function validProduct(int $businessId, int $productId): Product
    {
        $product = Product::query()
            ->where('id', $productId)
            ->where(function (Builder $query) use ($businessId) {
                $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->firstOrFail();

        if ($product->product_type === 'service' || $product->item_type === 'non_stock') {
            throw ValidationException::withMessages([
                'product_id' => 'Services and non-stock products are not allowed in opening stock.',
            ]);
        }

        return $product;
    }

    private function validateHeaderOwnership(int $businessId, array $data): void
    {
        if (!empty($data['branch_id'])) {
            Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        }

        if (!empty($data['warehouse_id'])) {
            Warehouse::query()->where('business_id', $businessId)->where('id', $data['warehouse_id'])->firstOrFail();
        }
    }

    private function validateVoucher(OpeningStockVoucher $voucher, int $businessId): void
    {
        abort_unless((int) $voucher->business_id === $businessId, 404);
    }

    private function hasLedgerPosting(OpeningStockVoucher $voucher): bool
    {
        return DB::table('stock_ledgers')
            ->where('business_id', $voucher->business_id)
            ->where('reference_type', OpeningStockVoucher::class)
            ->where('reference_id', $voucher->id)
            ->where('transaction_type', 'opening_stock')
            ->exists();
    }

    private function nextVoucherNumber(int $businessId): string
    {
        $prefix = 'OS-' . date('Y') . '-';
        $last = OpeningStockVoucher::query()
            ->where('business_id', $businessId)
            ->where('voucher_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('voucher_number');

        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function requiresBatch(Product $product): bool
    {
        return (bool) $product->batch_required || in_array($product->tracking_type, ['batch', 'batch_expiry'], true);
    }
}
