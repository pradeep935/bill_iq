<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductPurchasePrice;
use App\Models\ProductVariantItem;
use App\Models\PurchaseVoucher;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    private PurchaseCalculationService $calculator;
    private StockService $stock;

    public function __construct(PurchaseCalculationService $calculator, StockService $stock)
    {
        $this->calculator = $calculator;
        $this->stock = $stock;
    }

    public function list(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return PurchaseVoucher::query()
            ->with(['supplier', 'branch', 'warehouse', 'creator'])
            ->where('business_id', $businessId)
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('purchase_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('purchase_date', '<=', $filters['date_to']))
            ->when(!empty($filters['supplier_id']), fn (Builder $q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['payment_status']), fn (Builder $q) => $q->where('payment_status', $filters['payment_status']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['purchase_type']), fn (Builder $q) => $q->where('purchase_type', $filters['purchase_type']))
            ->when(!empty($filters['tax_type']), fn (Builder $q) => $q->where('tax_type', $filters['tax_type']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): PurchaseVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $this->validateOwnership($businessId, $data);
            $totals = $this->calculator->calculateVoucherTotals($data);
            $this->calculator->validatePurchaseTotals($totals);
            $status = $data['status'];

            $voucher = PurchaseVoucher::query()->create($this->voucherAttributes($businessId, $data, $totals, [
                'voucher_number' => $this->nextVoucherNumber($businessId),
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]));

            $this->syncItems($voucher, $totals['items']);

            if (in_array($status, ['confirmed', 'approved'], true)) {
                $this->post($voucher, $status);
            }

            AuditLogger::record([
                'module_name' => 'Purchase',
                'record_id' => $voucher->id,
                'action_type' => 'Create',
                'business_id' => $businessId,
                'summary' => 'Purchase voucher created',
            ]);

            return $this->fresh($voucher);
        });
    }

    public function update(PurchaseVoucher $voucher, array $data): PurchaseVoucher
    {
        return DB::transaction(function () use ($voucher, $data) {
            $this->assertBusiness($voucher);

            if ($voucher->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft purchases can be edited.']);
            }

            $businessId = AppController::businessId();
            $this->validateOwnership($businessId, $data);
            $totals = $this->calculator->calculateVoucherTotals($data);
            $this->calculator->validatePurchaseTotals($totals);
            $status = $data['status'];

            $voucher->update($this->voucherAttributes($businessId, $data, $totals));
            $voucher->items()->delete();
            $this->syncItems($voucher, $totals['items']);

            if (in_array($status, ['confirmed', 'approved'], true)) {
                $this->post($voucher, $status);
            }

            return $this->fresh($voucher);
        });
    }

    public function duplicate(PurchaseVoucher $voucher): PurchaseVoucher
    {
        return DB::transaction(function () use ($voucher) {
            $this->assertBusiness($voucher);
            $copy = $voucher->replicate();
            $copy->voucher_number = $this->nextVoucherNumber($voucher->business_id);
            $copy->supplier_invoice_number = null;
            $copy->status = 'draft';
            $copy->paid_amount = 0;
            $copy->balance_amount = $voucher->grand_total;
            $copy->payment_status = 'unpaid';
            $copy->approved_by = null;
            $copy->approved_at = null;
            $copy->created_by = Auth::id();
            $copy->save();

            foreach ($voucher->items as $item) {
                $copy->items()->create($item->replicate()->toArray());
            }

            return $this->fresh($copy);
        });
    }

    public function post(PurchaseVoucher $voucher, string $status = 'approved'): PurchaseVoucher
    {
        return DB::transaction(function () use ($voucher, $status) {
            $this->assertBusiness($voucher);

            if ($this->hasStockPosting($voucher)) {
                throw ValidationException::withMessages(['status' => 'Stock ledger already posted for this purchase.']);
            }

            $voucher->load(['items.product']);

            foreach ($voucher->items as $item) {
                $batchId = $this->batchIdForItem($voucher, $item);
                $stockQuantity = (float) $item->quantity + (float) $item->free_quantity;

                $this->stock->increaseStock([
                    'business_id' => $voucher->business_id,
                    'branch_id' => $voucher->branch_id,
                    'warehouse_id' => $voucher->warehouse_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'batch_id' => $batchId,
                    'transaction_type' => 'purchase',
                    'reference_type' => PurchaseVoucher::class,
                    'reference_id' => $voucher->id,
                    'quantity' => $stockQuantity,
                    'unit_cost' => $item->purchase_rate,
                    'transaction_date' => $voucher->purchase_date,
                    'remarks' => 'Purchase ' . $voucher->voucher_number,
                ]);

                ProductPurchasePrice::query()->create([
                    'business_id' => $voucher->business_id,
                    'product_id' => $item->product_id,
                    'supplier_id' => $voucher->supplier_id,
                    'purchase_id' => $voucher->id,
                    'purchase_item_id' => $item->id,
                    'batch_id' => $batchId,
                    'unit_cost' => $item->purchase_rate,
                    'tax_amount' => (float) $item->cgst_amount + (float) $item->sgst_amount + (float) $item->igst_amount + (float) $item->cess_amount,
                    'landed_cost' => $item->line_total / max((float) $item->quantity, 1),
                    'quantity' => $stockQuantity,
                    'purchase_date' => $voucher->purchase_date,
                ]);
            }

            $voucher->update([
                'status' => $status === 'confirmed' ? 'confirmed' : 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            if (\Illuminate\Support\Facades\Schema::hasTable('journal_vouchers')) {
                app(AccountingPostingService::class)->postPurchaseVoucher($voucher->fresh(['items']));
            }

            return $this->fresh($voucher);
        });
    }

    public function cancel(PurchaseVoucher $voucher): PurchaseVoucher
    {
        $this->assertBusiness($voucher);

        if ($voucher->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft purchases can be cancelled.']);
        }

        $voucher->update(['status' => 'cancelled']);

        return $this->fresh($voucher);
    }

    public function reverse(PurchaseVoucher $voucher, ?string $remarks = null): PurchaseVoucher
    {
        return DB::transaction(function () use ($voucher, $remarks) {
            $this->assertBusiness($voucher);

            if (!in_array($voucher->status, ['confirmed', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Only posted purchases can be reversed.']);
            }

            $this->stock->reverseTransaction(PurchaseVoucher::class, $voucher->id, $remarks ?: 'Purchase reversal');
            $voucher->update(['status' => 'reversed']);

            return $this->fresh($voucher);
        });
    }

    public function references(): array
    {
        $businessId = AppController::businessId();

        return [
            'suppliers' => Supplier::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('supplier_name')->get(['id', 'supplier_code', 'supplier_name', 'name', 'state_id', 'credit_days']),
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'branch_id', 'name', 'code']),
        ];
    }

    public function searchProducts(string $search)
    {
        $businessId = AppController::businessId();

        return Product::query()
            ->with(['barcodes', 'variantItems', 'batches' => fn ($q) => $q->where('status', 'active')->orderBy('batch_no')])
            ->where(function (Builder $q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->where('product_type', 'goods')
            ->where('item_type', 'stock')
            ->where('status', 'active')
            ->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
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
                'unit_id' => $product->unit_id,
                'unit' => $product->unit ?: 'PCS',
                'gst_rate' => (float) $product->gst_rate,
                'cess_rate' => (float) $product->cess_rate,
                'purchase_rate' => (float) ($product->cost_price ?: $product->purchase_price ?: $product->default_purchase_price),
                'selling_price' => (float) ($product->selling_price ?: $product->sale_price),
                'mrp' => $product->mrp !== null ? (float) $product->mrp : null,
                'tracking_type' => $product->tracking_type ?: 'none',
                'batch_required' => (bool) $product->batch_required,
                'expiry_required' => (bool) $product->expiry_required,
                'variants' => $product->variantItems->map(fn (ProductVariantItem $variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                ])->values(),
                'batches' => $product->batches->map(fn (ProductBatch $batch) => [
                    'id' => $batch->id,
                    'batch_no' => $batch->batch_no ?: $batch->batch_number,
                    'expiry_date' => optional($batch->expiry_date)->format('Y-m-d'),
                ])->values(),
            ]);
    }

    public function present(PurchaseVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'purchase_date' => optional($voucher->purchase_date)->format('Y-m-d'),
            'supplier_id' => $voucher->supplier_id,
            'supplier' => optional($voucher->supplier)->supplier_name ?: optional($voucher->supplier)->name,
            'branch_id' => $voucher->branch_id,
            'branch' => optional($voucher->branch)->name,
            'warehouse_id' => $voucher->warehouse_id,
            'warehouse' => optional($voucher->warehouse)->name,
            'supplier_invoice_number' => $voucher->supplier_invoice_number,
            'supplier_invoice_date' => optional($voucher->supplier_invoice_date)->format('Y-m-d'),
            'due_date' => optional($voucher->due_date)->format('Y-m-d'),
            'purchase_type' => $voucher->purchase_type,
            'tax_type' => $voucher->tax_type,
            'subtotal' => (float) $voucher->subtotal,
            'discount_type' => $voucher->discount_type,
            'discount_value' => (float) $voucher->discount_value,
            'discount_amount' => (float) $voucher->discount_amount,
            'taxable_amount' => (float) $voucher->taxable_amount,
            'cgst_amount' => (float) $voucher->cgst_amount,
            'sgst_amount' => (float) $voucher->sgst_amount,
            'igst_amount' => (float) $voucher->igst_amount,
            'cess_amount' => (float) $voucher->cess_amount,
            'round_off' => (float) $voucher->round_off,
            'grand_total' => (float) $voucher->grand_total,
            'paid_amount' => (float) $voucher->paid_amount,
            'balance_amount' => (float) $voucher->balance_amount,
            'payment_status' => $voucher->payment_status,
            'status' => $voucher->status,
            'created_by' => optional($voucher->creator)->name,
            'remarks' => $voucher->remarks,
            'items' => $voucher->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => optional($item->product)->name,
                'product_variant_id' => $item->product_variant_id,
                'batch_id' => $item->batch_id,
                'quantity' => (float) $item->quantity,
                'free_quantity' => (float) $item->free_quantity,
                'unit_id' => $item->unit_id,
                'purchase_rate' => (float) $item->purchase_rate,
                'selling_price' => $item->selling_price !== null ? (float) $item->selling_price : null,
                'mrp' => $item->mrp !== null ? (float) $item->mrp : null,
                'discount_type' => $item->discount_type,
                'discount_value' => (float) $item->discount_value,
                'gst_rate' => (float) $item->gst_rate,
                'cess_rate' => (float) $item->cess_rate,
                'taxable_amount' => (float) $item->taxable_amount,
                'line_total' => (float) $item->line_total,
                'batch_number' => $item->batch_number,
                'manufacturing_date' => optional($item->manufacturing_date)->format('Y-m-d'),
                'expiry_date' => optional($item->expiry_date)->format('Y-m-d'),
                'warehouse_location' => $item->warehouse_location,
                'remarks' => $item->remarks,
            ])->values(),
        ];
    }

    private function voucherAttributes(int $businessId, array $data, array $totals, array $extra = []): array
    {
        return array_merge([
            'business_id' => $businessId,
            'branch_id' => $data['branch_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null,
            'purchase_date' => $data['purchase_date'],
            'supplier_invoice_date' => $data['supplier_invoice_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'purchase_type' => $data['purchase_type'],
            'tax_type' => $data['tax_type'],
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? 0,
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'taxable_amount' => $totals['taxable_amount'],
            'cgst_amount' => $totals['cgst_amount'],
            'sgst_amount' => $totals['sgst_amount'],
            'igst_amount' => $totals['igst_amount'],
            'cess_amount' => $totals['cess_amount'],
            'round_off' => $totals['round_off'],
            'grand_total' => $totals['grand_total'],
            'paid_amount' => $totals['paid_amount'],
            'balance_amount' => $totals['balance_amount'],
            'payment_status' => $totals['payment_status'],
            'remarks' => $data['remarks'] ?? null,
        ], $extra);
    }

    private function syncItems(PurchaseVoucher $voucher, array $items): void
    {
        foreach ($items as $item) {
            $voucher->items()->create([
                'product_id' => $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'batch_id' => $item['batch_id'] ?? null,
                'quantity' => $item['quantity'],
                'free_quantity' => $item['free_quantity'] ?? 0,
                'unit_id' => $item['unit_id'] ?? null,
                'purchase_rate' => $item['purchase_rate'],
                'selling_price' => $item['selling_price'] ?? null,
                'mrp' => $item['mrp'] ?? null,
                'discount_type' => $item['discount_type'] ?? null,
                'discount_value' => $item['discount_value'] ?? 0,
                'discount_amount' => $item['discount_amount'],
                'taxable_amount' => $item['taxable_amount'],
                'gst_rate' => $item['gst_rate'],
                'cgst_rate' => $item['cgst_rate'],
                'sgst_rate' => $item['sgst_rate'],
                'igst_rate' => $item['igst_rate'],
                'cgst_amount' => $item['cgst_amount'],
                'sgst_amount' => $item['sgst_amount'],
                'igst_amount' => $item['igst_amount'],
                'cess_rate' => $item['cess_rate'],
                'cess_amount' => $item['cess_amount'],
                'line_total' => $item['line_total'],
                'batch_number' => $item['batch_number'] ?? null,
                'manufacturing_date' => $item['manufacturing_date'] ?? null,
                'expiry_date' => $item['expiry_date'] ?? null,
                'warehouse_location' => $item['warehouse_location'] ?? null,
                'remarks' => $item['remarks'] ?? null,
            ]);
        }
    }

    private function batchIdForItem(PurchaseVoucher $voucher, $item): ?int
    {
        if ($item->batch_id) {
            return $item->batch_id;
        }

        if (!$item->batch_number) {
            return null;
        }

        $batch = ProductBatch::query()->firstOrCreate(
            [
                'business_id' => $voucher->business_id,
                'product_id' => $item->product_id,
                'batch_no' => $item->batch_number,
            ],
            [
                'batch_number' => $item->batch_number,
                'manufacturing_date' => $item->manufacturing_date,
                'expiry_date' => $item->expiry_date,
                'purchase_price' => $item->purchase_rate,
                'cost_price' => $item->purchase_rate,
                'selling_price' => $item->selling_price ?: 0,
                'mrp' => $item->mrp,
                'quantity' => 0,
                'status' => 'active',
            ]
        );

        $item->update(['batch_id' => $batch->id]);

        return $batch->id;
    }

    private function validateOwnership(int $businessId, array $data): void
    {
        Supplier::query()->where('business_id', $businessId)->where('id', $data['supplier_id'])->firstOrFail();

        if (!empty($data['branch_id'])) {
            Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        }

        if (!empty($data['warehouse_id'])) {
            Warehouse::query()->where('business_id', $businessId)->where('id', $data['warehouse_id'])->firstOrFail();
        }

        foreach ($data['items'] as $index => $item) {
            $product = Product::query()
                ->where('id', $item['product_id'])
                ->where(function (Builder $q) use ($businessId) {
                    $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
                })
                ->firstOrFail();

            if ($product->product_type === 'service' || $product->item_type === 'non_stock') {
                throw ValidationException::withMessages(["items.$index.product_id" => 'Services and non-stock products cannot be posted to stock.']);
            }

            if (($product->batch_required || in_array($product->tracking_type, ['batch', 'batch_expiry'], true)) && empty($item['batch_number']) && empty($item['batch_id'])) {
                throw ValidationException::withMessages(["items.$index.batch_number" => 'Batch number is required for this product.']);
            }

            if (!empty($item['product_variant_id'])) {
                ProductVariantItem::query()
                    ->where('business_id', $businessId)
                    ->where('product_id', $product->id)
                    ->where('id', $item['product_variant_id'])
                    ->firstOrFail();
            }
        }
    }

    private function hasStockPosting(PurchaseVoucher $voucher): bool
    {
        return DB::table('stock_ledgers')
            ->where('business_id', $voucher->business_id)
            ->where('reference_type', PurchaseVoucher::class)
            ->where('reference_id', $voucher->id)
            ->where('transaction_type', 'purchase')
            ->exists();
    }

    private function assertBusiness(PurchaseVoucher $voucher): void
    {
        abort_unless((int) $voucher->business_id === AppController::businessId(), 404);
    }

    private function fresh(PurchaseVoucher $voucher): PurchaseVoucher
    {
        return $voucher->fresh(['supplier', 'branch', 'warehouse', 'creator', 'items.product', 'items.variant', 'items.batch']);
    }

    private function nextVoucherNumber(int $businessId): string
    {
        $prefix = 'PUR-' . date('Y') . '-';
        $last = PurchaseVoucher::query()
            ->where('business_id', $businessId)
            ->where('voucher_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('voucher_number');
        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
