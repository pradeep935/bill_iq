<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariantItem;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturnVoucher;
use App\Models\PurchaseVoucher;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReturnService
{
    private PurchaseCalculationService $calculator;
    private StockService $stock;
    private PurchaseService $purchases;

    public function __construct(PurchaseCalculationService $calculator, StockService $stock, PurchaseService $purchases)
    {
        $this->calculator = $calculator;
        $this->stock = $stock;
        $this->purchases = $purchases;
    }

    public function list(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return PurchaseReturnVoucher::query()
            ->with(['supplier', 'purchase', 'branch', 'warehouse', 'creator', 'items.product'])
            ->where('business_id', $businessId)
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('return_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('return_date', '<=', $filters['date_to']))
            ->when(!empty($filters['supplier_id']), fn (Builder $q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['return_type']), fn (Builder $q) => $q->where('return_type', $filters['return_type']))
            ->when(!empty($filters['settlement_type']), fn (Builder $q) => $q->where('settlement_type', $filters['settlement_type']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): PurchaseReturnVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $this->validateOwnership($businessId, $data);
            $totals = $this->calculateTotals($data);
            $status = $data['status'];

            $voucher = PurchaseReturnVoucher::query()->create($this->voucherAttributes($businessId, $data, $totals, [
                'voucher_number' => $this->nextVoucherNumber($businessId),
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]));

            $this->syncItems($voucher, $totals['items']);

            if (in_array($status, ['confirmed', 'approved'], true)) {
                $this->post($voucher, $status);
            }

            AuditLogger::record([
                'module_name' => 'Purchase Return',
                'record_id' => $voucher->id,
                'action_type' => 'Create',
                'business_id' => $businessId,
                'summary' => 'Purchase return voucher created',
            ]);

            return $this->fresh($voucher);
        });
    }

    public function update(PurchaseReturnVoucher $voucher, array $data): PurchaseReturnVoucher
    {
        return DB::transaction(function () use ($voucher, $data) {
            $this->assertBusiness($voucher);

            if ($voucher->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft purchase returns can be edited.']);
            }

            $businessId = AppController::businessId();
            $this->validateOwnership($businessId, $data, $voucher->id);
            $totals = $this->calculateTotals($data, $voucher->id);
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

    public function post(PurchaseReturnVoucher $voucher, string $status = 'approved'): PurchaseReturnVoucher
    {
        return DB::transaction(function () use ($voucher, $status) {
            $this->assertBusiness($voucher);

            if ($this->hasStockPosting($voucher)) {
                throw ValidationException::withMessages(['status' => 'Stock ledger already posted for this return.']);
            }

            $voucher->load('items.product');

            foreach ($voucher->items as $item) {
                if ($item->product->product_type === 'service' || $item->product->item_type === 'non_stock') {
                    continue;
                }

                $this->stock->decreaseStock([
                    'business_id' => $voucher->business_id,
                    'branch_id' => $voucher->branch_id,
                    'warehouse_id' => $voucher->warehouse_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'batch_id' => $item->batch_id,
                    'transaction_type' => 'purchase_return',
                    'reference_type' => PurchaseReturnVoucher::class,
                    'reference_id' => $voucher->id,
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->purchase_rate,
                    'transaction_date' => $voucher->return_date,
                    'remarks' => 'Purchase return ' . $voucher->voucher_number,
                ]);
            }

            $voucher->update([
                'status' => $status === 'confirmed' ? 'confirmed' : 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            if (\Illuminate\Support\Facades\Schema::hasTable('journal_vouchers')) {
                app(AccountingPostingService::class)->postPurchaseReturn($voucher->fresh(['items']));
            }

            AuditLogger::record([
                'module_name' => 'Purchase Return',
                'record_id' => $voucher->id,
                'action_type' => 'Post',
                'business_id' => $voucher->business_id,
                'summary' => 'Purchase return posted to stock ledger and supplier settlement prepared',
            ]);

            return $this->fresh($voucher);
        });
    }

    public function cancel(PurchaseReturnVoucher $voucher): PurchaseReturnVoucher
    {
        $this->assertBusiness($voucher);

        if ($voucher->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft purchase returns can be cancelled.']);
        }

        $voucher->update(['status' => 'cancelled']);

        return $this->fresh($voucher);
    }

    public function reverse(PurchaseReturnVoucher $voucher, ?string $remarks = null): PurchaseReturnVoucher
    {
        return DB::transaction(function () use ($voucher, $remarks) {
            $this->assertBusiness($voucher);

            if (!in_array($voucher->status, ['confirmed', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Only posted purchase returns can be reversed.']);
            }

            $this->stock->reverseTransaction(PurchaseReturnVoucher::class, $voucher->id, $remarks ?: 'Purchase return reversal');
            $voucher->update(['status' => 'reversed']);

            return $this->fresh($voucher);
        });
    }

    public function references(): array
    {
        return $this->purchases->references();
    }

    public function searchProducts(string $search)
    {
        return $this->purchases->searchProducts($search);
    }

    public function searchPurchases(string $search)
    {
        $businessId = AppController::businessId();

        return PurchaseVoucher::query()
            ->with(['supplier', 'branch', 'warehouse'])
            ->where('business_id', $businessId)
            ->whereIn('status', ['confirmed', 'approved'])
            ->where(function (Builder $q) use ($search) {
                $q->where('voucher_number', 'like', '%' . $search . '%')
                    ->orWhere('supplier_invoice_number', 'like', '%' . $search . '%');
            })
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (PurchaseVoucher $voucher) => [
                'id' => $voucher->id,
                'voucher_number' => $voucher->voucher_number,
                'supplier_id' => $voucher->supplier_id,
                'supplier' => optional($voucher->supplier)->supplier_name ?: optional($voucher->supplier)->name,
                'branch_id' => $voucher->branch_id,
                'warehouse_id' => $voucher->warehouse_id,
                'tax_type' => $voucher->tax_type,
                'purchase_date' => optional($voucher->purchase_date)->format('Y-m-d'),
            ]);
    }

    public function purchaseItems(int $purchaseId)
    {
        $purchase = PurchaseVoucher::query()
            ->with(['items.product', 'items.variant', 'items.batch'])
            ->where('business_id', AppController::businessId())
            ->whereIn('status', ['confirmed', 'approved'])
            ->where('id', $purchaseId)
            ->firstOrFail();

        return $purchase->items->map(function (PurchaseItem $item) {
            $returned = $this->returnedQuantity($item->id);
            $available = (float) $item->quantity + (float) $item->free_quantity - $returned;

            return [
                'purchase_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product' => optional($item->product)->name,
                'sku' => optional($item->product)->sku,
                'product_variant_id' => $item->product_variant_id,
                'variant' => optional($item->variant)->sku,
                'batch_id' => $item->batch_id,
                'batch' => $item->batch_number ?: optional($item->batch)->batch_no,
                'unit_id' => $item->unit_id,
                'purchased_quantity' => (float) $item->quantity + (float) $item->free_quantity,
                'previously_returned' => $returned,
                'available_quantity' => max(0, $available),
                'quantity' => max(0, $available),
                'purchase_rate' => (float) $item->purchase_rate,
                'discount_amount' => (float) $item->discount_amount,
                'gst_rate' => (float) $item->gst_rate,
                'cess_rate' => (float) $item->cess_rate,
                'taxable_amount' => (float) $item->taxable_amount,
                'line_total' => (float) $item->line_total,
            ];
        })->values();
    }

    public function present(PurchaseReturnVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'return_date' => optional($voucher->return_date)->format('Y-m-d'),
            'purchase_voucher_id' => $voucher->purchase_voucher_id,
            'purchase_number' => optional($voucher->purchase)->voucher_number,
            'supplier_id' => $voucher->supplier_id,
            'supplier' => optional($voucher->supplier)->supplier_name ?: optional($voucher->supplier)->name,
            'branch_id' => $voucher->branch_id,
            'branch' => optional($voucher->branch)->name,
            'warehouse_id' => $voucher->warehouse_id,
            'warehouse' => optional($voucher->warehouse)->name,
            'supplier_debit_note_number' => $voucher->supplier_debit_note_number,
            'reason' => $voucher->reason,
            'return_type' => $voucher->return_type,
            'tax_type' => $voucher->tax_type,
            'subtotal' => (float) $voucher->subtotal,
            'discount_amount' => (float) $voucher->discount_amount,
            'taxable_amount' => (float) $voucher->taxable_amount,
            'cgst_amount' => (float) $voucher->cgst_amount,
            'sgst_amount' => (float) $voucher->sgst_amount,
            'igst_amount' => (float) $voucher->igst_amount,
            'cess_amount' => (float) $voucher->cess_amount,
            'round_off' => (float) $voucher->round_off,
            'grand_total' => (float) $voucher->grand_total,
            'settlement_type' => $voucher->settlement_type,
            'settlement_amount' => (float) $voucher->settlement_amount,
            'balance_amount' => (float) $voucher->balance_amount,
            'status' => $voucher->status,
            'created_by' => optional($voucher->creator)->name,
            'remarks' => $voucher->remarks,
            'items' => $voucher->items->map(fn ($item) => [
                'id' => $item->id,
                'purchase_item_id' => $item->purchase_item_id,
                'product_id' => $item->product_id,
                'product' => optional($item->product)->name,
                'product_variant_id' => $item->product_variant_id,
                'batch_id' => $item->batch_id,
                'unit_id' => $item->unit_id,
                'quantity' => (float) $item->quantity,
                'purchase_rate' => (float) $item->purchase_rate,
                'discount_amount' => (float) $item->discount_amount,
                'gst_rate' => (float) $item->gst_rate,
                'cess_rate' => (float) $item->cess_rate,
                'taxable_amount' => (float) $item->taxable_amount,
                'line_total' => (float) $item->line_total,
                'reason' => $item->reason,
            ])->values(),
        ];
    }

    private function calculateTotals(array $data, ?int $currentReturnId = null): array
    {
        $items = [];
        $subtotal = $discount = $taxable = $cgst = $sgst = $igst = $cess = 0.0;

        foreach ($data['items'] as $item) {
            if ($data['return_type'] === 'against_purchase') {
                $line = $this->linkedLine($item, $data['tax_type'], $currentReturnId);
            } else {
                $line = $this->calculator->calculateLineTax([
                    'quantity' => $item['quantity'],
                    'purchase_rate' => $item['purchase_rate'],
                    'discount_type' => 'fixed',
                    'discount_value' => $item['discount_amount'] ?? 0,
                    'gst_rate' => $item['gst_rate'] ?? 0,
                    'cess_rate' => $item['cess_rate'] ?? 0,
                ], $data['tax_type']);
            }

            $subtotal += $line['gross_amount'];
            $discount += $line['discount_amount'];
            $taxable += $line['taxable_amount'];
            $cgst += $line['cgst_amount'];
            $sgst += $line['sgst_amount'];
            $igst += $line['igst_amount'];
            $cess += $line['cess_amount'];
            $items[] = array_merge($item, $line);
        }

        $grand = round($taxable + $cgst + $sgst + $igst + $cess, 2);
        $rounded = round($grand);
        $settlement = round((float) ($data['settlement_amount'] ?? $rounded), 2);

        return [
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'taxable_amount' => round($taxable, 2),
            'cgst_amount' => round($cgst, 2),
            'sgst_amount' => round($sgst, 2),
            'igst_amount' => round($igst, 2),
            'cess_amount' => round($cess, 2),
            'round_off' => round($rounded - $grand, 2),
            'grand_total' => $rounded,
            'settlement_amount' => min($settlement, $rounded),
            'balance_amount' => round(max(0, $rounded - $settlement), 2),
        ];
    }

    private function linkedLine(array $item, string $taxType, ?int $currentReturnId): array
    {
        $purchaseItem = PurchaseItem::query()->findOrFail($item['purchase_item_id']);
        $baseQty = (float) $purchaseItem->quantity + (float) $purchaseItem->free_quantity;
        $available = $baseQty - $this->returnedQuantity($purchaseItem->id, $currentReturnId);
        $quantity = (float) $item['quantity'];

        if ($quantity > $available) {
            throw ValidationException::withMessages(['quantity' => 'Return quantity cannot exceed available return quantity.']);
        }

        $ratio = $baseQty > 0 ? $quantity / $baseQty : 0;
        $gstAmount = $taxType === 'interstate' ? (float) $purchaseItem->igst_amount : ((float) $purchaseItem->cgst_amount + (float) $purchaseItem->sgst_amount);

        return [
            'gross_amount' => round((float) $purchaseItem->purchase_rate * $quantity, 2),
            'discount_amount' => round((float) $purchaseItem->discount_amount * $ratio, 2),
            'taxable_amount' => round((float) $purchaseItem->taxable_amount * $ratio, 2),
            'gst_rate' => (float) $purchaseItem->gst_rate,
            'cgst_rate' => $taxType === 'intrastate' ? (float) $purchaseItem->cgst_rate : 0,
            'sgst_rate' => $taxType === 'intrastate' ? (float) $purchaseItem->sgst_rate : 0,
            'igst_rate' => $taxType === 'interstate' ? (float) $purchaseItem->igst_rate : 0,
            'cgst_amount' => $taxType === 'intrastate' ? round((float) $purchaseItem->cgst_amount * $ratio, 2) : 0,
            'sgst_amount' => $taxType === 'intrastate' ? round((float) $purchaseItem->sgst_amount * $ratio, 2) : 0,
            'igst_amount' => $taxType === 'interstate' ? round($gstAmount * $ratio, 2) : 0,
            'cess_rate' => (float) $purchaseItem->cess_rate,
            'cess_amount' => round((float) $purchaseItem->cess_amount * $ratio, 2),
            'line_total' => round((float) $purchaseItem->line_total * $ratio, 2),
            'purchase_rate' => (float) $purchaseItem->purchase_rate,
            'product_id' => $purchaseItem->product_id,
            'product_variant_id' => $purchaseItem->product_variant_id,
            'batch_id' => $purchaseItem->batch_id,
            'unit_id' => $purchaseItem->unit_id,
        ];
    }

    private function voucherAttributes(int $businessId, array $data, array $totals, array $extra = []): array
    {
        return array_merge([
            'business_id' => $businessId,
            'branch_id' => $data['branch_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'purchase_voucher_id' => $data['return_type'] === 'against_purchase' ? ($data['purchase_voucher_id'] ?? null) : null,
            'return_date' => $data['return_date'],
            'supplier_debit_note_number' => $data['supplier_debit_note_number'] ?? null,
            'reason' => $data['reason'] ?? null,
            'return_type' => $data['return_type'],
            'tax_type' => $data['tax_type'],
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'taxable_amount' => $totals['taxable_amount'],
            'cgst_amount' => $totals['cgst_amount'],
            'sgst_amount' => $totals['sgst_amount'],
            'igst_amount' => $totals['igst_amount'],
            'cess_amount' => $totals['cess_amount'],
            'round_off' => $totals['round_off'],
            'grand_total' => $totals['grand_total'],
            'settlement_type' => $data['settlement_type'],
            'settlement_amount' => $totals['settlement_amount'],
            'balance_amount' => $totals['balance_amount'],
            'remarks' => $data['remarks'] ?? null,
        ], $extra);
    }

    private function syncItems(PurchaseReturnVoucher $voucher, array $items): void
    {
        foreach ($items as $item) {
            $voucher->items()->create([
                'purchase_item_id' => $item['purchase_item_id'] ?? null,
                'product_id' => $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'batch_id' => $item['batch_id'] ?? null,
                'unit_id' => $item['unit_id'] ?? null,
                'quantity' => $item['quantity'],
                'purchase_rate' => $item['purchase_rate'],
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
                'reason' => $item['reason'] ?? null,
            ]);
        }
    }

    private function validateOwnership(int $businessId, array $data, ?int $currentReturnId = null): void
    {
        Supplier::query()->where('business_id', $businessId)->where('id', $data['supplier_id'])->firstOrFail();

        if (!empty($data['branch_id'])) {
            Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        }

        if (!empty($data['warehouse_id'])) {
            Warehouse::query()->where('business_id', $businessId)->where('id', $data['warehouse_id'])->firstOrFail();
        }

        $purchase = null;

        if ($data['return_type'] === 'against_purchase') {
            $purchase = PurchaseVoucher::query()
                ->where('business_id', $businessId)
                ->whereIn('status', ['confirmed', 'approved'])
                ->where('id', $data['purchase_voucher_id'])
                ->firstOrFail();

            if ((int) $purchase->supplier_id !== (int) $data['supplier_id']) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier must match original purchase.']);
            }

            if ((int) ($purchase->branch_id ?: 0) !== (int) ($data['branch_id'] ?: 0)) {
                throw ValidationException::withMessages(['branch_id' => 'Branch must match original purchase.']);
            }

            if ((int) ($purchase->warehouse_id ?: 0) !== (int) ($data['warehouse_id'] ?: 0)) {
                throw ValidationException::withMessages(['warehouse_id' => 'Warehouse must match original purchase.']);
            }
        }

        foreach ($data['items'] as $index => $item) {
            if ($purchase) {
                $purchaseItem = PurchaseItem::query()
                    ->where('purchase_voucher_id', $purchase->id)
                    ->where('id', $item['purchase_item_id'])
                    ->firstOrFail();

                if ((int) $purchaseItem->product_id !== (int) $item['product_id']) {
                    throw ValidationException::withMessages(["items.$index.product_id" => 'Product must match original purchase item.']);
                }

                continue;
            }

            $product = Product::query()
                ->where('id', $item['product_id'])
                ->where(function (Builder $q) use ($businessId) {
                    $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
                })
                ->firstOrFail();

            if ($product->product_type === 'service' || $product->item_type === 'non_stock') {
                throw ValidationException::withMessages(["items.$index.product_id" => 'Services and non-stock products cannot create stock return entries.']);
            }

            if (($product->batch_required || in_array($product->tracking_type, ['batch', 'batch_expiry'], true)) && empty($item['batch_id'])) {
                throw ValidationException::withMessages(["items.$index.batch_id" => 'Batch is required for this product.']);
            }

            if (!empty($item['product_variant_id'])) {
                ProductVariantItem::query()->where('business_id', $businessId)->where('product_id', $product->id)->where('id', $item['product_variant_id'])->firstOrFail();
            }

            if (!empty($item['batch_id'])) {
                ProductBatch::query()->where('business_id', $businessId)->where('product_id', $product->id)->where('id', $item['batch_id'])->firstOrFail();
            }
        }
    }

    private function returnedQuantity(int $purchaseItemId, ?int $excludeReturnId = null): float
    {
        return (float) DB::table('purchase_return_items')
            ->join('purchase_return_vouchers', 'purchase_return_vouchers.id', '=', 'purchase_return_items.purchase_return_voucher_id')
            ->where('purchase_return_items.purchase_item_id', $purchaseItemId)
            ->whereIn('purchase_return_vouchers.status', ['confirmed', 'approved'])
            ->when($excludeReturnId, fn ($q) => $q->where('purchase_return_vouchers.id', '!=', $excludeReturnId))
            ->sum('purchase_return_items.quantity');
    }

    private function hasStockPosting(PurchaseReturnVoucher $voucher): bool
    {
        return DB::table('stock_ledgers')
            ->where('business_id', $voucher->business_id)
            ->where('reference_type', PurchaseReturnVoucher::class)
            ->where('reference_id', $voucher->id)
            ->where('transaction_type', 'purchase_return')
            ->exists();
    }

    private function assertBusiness(PurchaseReturnVoucher $voucher): void
    {
        abort_unless((int) $voucher->business_id === AppController::businessId(), 404);
    }

    private function fresh(PurchaseReturnVoucher $voucher): PurchaseReturnVoucher
    {
        return $voucher->fresh(['supplier', 'purchase', 'branch', 'warehouse', 'creator', 'items.product', 'items.variant', 'items.batch']);
    }

    private function nextVoucherNumber(int $businessId): string
    {
        $prefix = 'PR-' . date('Y') . '-';
        $last = PurchaseReturnVoucher::query()
            ->where('business_id', $businessId)
            ->where('voucher_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('voucher_number');
        $next = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
