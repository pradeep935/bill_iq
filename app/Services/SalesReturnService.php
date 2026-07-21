<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariantItem;
use App\Models\SalesItem;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    private SalesCalculationService $calculator;
    private SalesInvoiceNumberService $numbers;
    private StockService $stock;
    private SalesService $sales;

    public function __construct(SalesCalculationService $calculator, SalesInvoiceNumberService $numbers, StockService $stock, SalesService $sales)
    {
        $this->calculator = $calculator;
        $this->numbers = $numbers;
        $this->stock = $stock;
        $this->sales = $sales;
    }

    public function list(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return SalesReturnVoucher::query()
            ->with(['customer', 'sale', 'branch', 'warehouse', 'creator', 'items', 'refunds.method'])
            ->where('business_id', $businessId)
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('return_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('return_date', '<=', $filters['date_to']))
            ->when(!empty($filters['customer_id']), fn (Builder $q) => $q->where('customer_id', $filters['customer_id']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['return_type']), fn (Builder $q) => $q->where('return_type', $filters['return_type']))
            ->when(!empty($filters['settlement_type']), fn (Builder $q) => $q->where('settlement_type', $filters['settlement_type']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): SalesReturnVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $this->validateOwnership($businessId, $data);
            $totals = $this->calculateTotals($data);
            $numbers = $this->numbers->nextCreditNote($businessId, $data['branch_id'] ?? null);
            $status = $data['status'];

            $voucher = SalesReturnVoucher::query()->create($this->voucherAttributes($businessId, $data, $totals, array_merge($numbers, [
                'status' => 'draft',
                'created_by' => Auth::id(),
            ])));

            $this->syncItems($voucher, $totals['items']);
            $this->syncRefunds($voucher, $data['refunds'] ?? []);

            if (in_array($status, ['confirmed', 'approved'], true)) {
                $this->post($voucher, $status);
            }

            AuditLogger::record([
                'module_name' => 'Sales Return',
                'record_id' => $voucher->id,
                'action_type' => 'Create',
                'business_id' => $businessId,
                'summary' => 'Sales return credit note created',
            ]);

            return $this->fresh($voucher);
        });
    }

    public function update(SalesReturnVoucher $voucher, array $data): SalesReturnVoucher
    {
        return DB::transaction(function () use ($voucher, $data) {
            $this->assertBusiness($voucher);

            if ($voucher->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft sales returns can be edited.']);
            }

            $businessId = AppController::businessId();
            $this->validateOwnership($businessId, $data, $voucher->id);
            $totals = $this->calculateTotals($data, $voucher->id);
            $status = $data['status'];

            $voucher->update($this->voucherAttributes($businessId, $data, $totals));
            $voucher->items()->delete();
            $voucher->refunds()->delete();
            $this->syncItems($voucher, $totals['items']);
            $this->syncRefunds($voucher, $data['refunds'] ?? []);

            if (in_array($status, ['confirmed', 'approved'], true)) {
                $this->post($voucher, $status);
            }

            return $this->fresh($voucher);
        });
    }

    public function post(SalesReturnVoucher $voucher, string $status = 'approved'): SalesReturnVoucher
    {
        return DB::transaction(function () use ($voucher, $status) {
            $this->assertBusiness($voucher);

            if ($this->hasStockPosting($voucher)) {
                throw ValidationException::withMessages(['status' => 'Stock ledger already posted for this sales return.']);
            }

            if ($voucher->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft sales returns can be posted.']);
            }

            $voucher->load(['items.product']);

            foreach ($voucher->items as $item) {
                if ($item->product->product_type === 'service' || $item->product->item_type === 'non_stock') {
                    continue;
                }

                if ($item->restock_status === 'non_restockable') {
                    AuditLogger::record([
                        'module_name' => 'Sales Return',
                        'record_id' => $voucher->id,
                        'action_type' => 'Non Restockable',
                        'business_id' => $voucher->business_id,
                        'summary' => 'Returned item kept out of stock ledger',
                    ]);
                    continue;
                }

                if (in_array($item->restock_status, ['damaged_stock', 'expired_stock'], true)) {
                    AuditLogger::record([
                        'module_name' => 'Sales Return',
                        'record_id' => $voucher->id,
                        'action_type' => 'Quarantine Stock',
                        'business_id' => $voucher->business_id,
                        'summary' => 'Returned item requires damaged or expired stock handling before resale',
                    ]);
                    continue;
                }

                $this->stock->increaseStock([
                    'business_id' => $voucher->business_id,
                    'branch_id' => $voucher->branch_id,
                    'warehouse_id' => $voucher->warehouse_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'batch_id' => $item->batch_id,
                    'transaction_type' => 'sales_return',
                    'reference_type' => SalesReturnVoucher::class,
                    'reference_id' => $voucher->id,
                    'quantity' => $item->quantity,
                    'unit_cost' => $this->stock->getAverageCost([
                        'business_id' => $voucher->business_id,
                        'branch_id' => $voucher->branch_id,
                        'warehouse_id' => $voucher->warehouse_id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'batch_id' => $item->batch_id,
                    ]),
                    'transaction_date' => $voucher->return_date,
                    'remarks' => 'Sales return ' . $voucher->credit_note_number,
                ]);
            }

            $voucher->update([
                'status' => $status === 'confirmed' ? 'confirmed' : 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            if (\Illuminate\Support\Facades\Schema::hasTable('journal_vouchers')) {
                app(AccountingPostingService::class)->postSalesReturn($voucher->fresh(['items']));
            }

            return $this->fresh($voucher);
        });
    }

    public function cancel(SalesReturnVoucher $voucher): SalesReturnVoucher
    {
        $this->assertBusiness($voucher);

        if ($voucher->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft sales returns can be cancelled.']);
        }

        $voucher->update(['status' => 'cancelled', 'cancelled_by' => Auth::id(), 'cancelled_at' => now()]);

        return $this->fresh($voucher);
    }

    public function reverse(SalesReturnVoucher $voucher, ?string $remarks = null): SalesReturnVoucher
    {
        return DB::transaction(function () use ($voucher, $remarks) {
            $this->assertBusiness($voucher);

            if (!in_array($voucher->status, ['confirmed', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Only posted sales returns can be reversed.']);
            }

            $this->stock->reverseTransaction(SalesReturnVoucher::class, $voucher->id, $remarks ?: 'Sales return reversal');
            $voucher->update(['status' => 'reversed']);

            return $this->fresh($voucher);
        });
    }

    public function references(): array
    {
        return $this->sales->references();
    }

    public function searchProducts(string $search, array $scope = [])
    {
        return $this->sales->searchProducts($search, $scope);
    }

    public function searchSales(string $search)
    {
        $businessId = AppController::businessId();

        return SalesVoucher::query()
            ->with(['customer', 'branch', 'warehouse'])
            ->where('business_id', $businessId)
            ->whereIn('status', ['confirmed', 'approved'])
            ->where(function (Builder $q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('invoice_number', 'like', $like)
                    ->orWhere('voucher_number', 'like', $like)
                    ->orWhere('customer_name_snapshot', 'like', $like)
                    ->orWhere('customer_mobile_snapshot', 'like', $like);
            })
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (SalesVoucher $voucher) => [
                'id' => $voucher->id,
                'invoice_number' => $voucher->invoice_number,
                'invoice_date' => optional($voucher->invoice_date)->format('Y-m-d'),
                'customer_id' => $voucher->customer_id,
                'customer' => $voucher->customer_name_snapshot ?: optional($voucher->customer)->customer_name,
                'branch_id' => $voucher->branch_id,
                'warehouse_id' => $voucher->warehouse_id,
                'tax_type' => $voucher->tax_type,
                'place_of_supply_state_id' => $voucher->place_of_supply_state_id,
            ]);
    }

    public function saleItems(int $saleId)
    {
        $sale = SalesVoucher::query()
            ->with(['items.product', 'items.variant', 'items.batch'])
            ->where('business_id', AppController::businessId())
            ->whereIn('status', ['confirmed', 'approved'])
            ->where('id', $saleId)
            ->firstOrFail();

        return $sale->items->map(function (SalesItem $item) {
            $returned = $this->returnedQuantity($item->id);
            $available = (float) $item->quantity + (float) $item->free_quantity - $returned;

            return [
                'sales_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'product_variant_id' => $item->product_variant_id,
                'variant' => optional($item->variant)->sku,
                'batch_id' => $item->batch_id,
                'batch' => optional($item->batch)->batch_no ?: optional($item->batch)->batch_number,
                'unit_id' => $item->unit_id,
                'sold_quantity' => (float) $item->quantity + (float) $item->free_quantity,
                'previously_returned' => $returned,
                'available_quantity' => max(0, $available),
                'quantity' => max(0, $available),
                'selling_rate' => (float) $item->selling_rate,
                'discount_amount' => (float) $item->discount_amount,
                'gst_rate' => (float) $item->gst_rate,
                'cess_rate' => (float) $item->cess_rate,
                'taxable_amount' => (float) $item->taxable_amount,
                'line_total' => (float) $item->line_total,
                'condition_status' => 'good',
                'restock_status' => 'restock',
            ];
        })->values();
    }

    public function present(SalesReturnVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'credit_note_number' => $voucher->credit_note_number,
            'return_date' => optional($voucher->return_date)->format('Y-m-d'),
            'sales_voucher_id' => $voucher->sales_voucher_id,
            'invoice_number' => optional($voucher->sale)->invoice_number,
            'customer_id' => $voucher->customer_id,
            'customer' => optional($voucher->customer)->customer_name ?: optional($voucher->sale)->customer_name_snapshot,
            'branch_id' => $voucher->branch_id,
            'branch' => optional($voucher->branch)->name,
            'warehouse_id' => $voucher->warehouse_id,
            'warehouse' => optional($voucher->warehouse)->name,
            'return_type' => $voucher->return_type,
            'tax_type' => $voucher->tax_type,
            'place_of_supply_state_id' => $voucher->place_of_supply_state_id,
            'subtotal' => (float) $voucher->subtotal,
            'discount_amount' => (float) $voucher->discount_amount,
            'taxable_amount' => (float) $voucher->taxable_amount,
            'cgst_amount' => (float) $voucher->cgst_amount,
            'sgst_amount' => (float) $voucher->sgst_amount,
            'igst_amount' => (float) $voucher->igst_amount,
            'cess_amount' => (float) $voucher->cess_amount,
            'round_off' => (float) $voucher->round_off,
            'grand_total' => (float) $voucher->grand_total,
            'refund_amount' => (float) $voucher->refund_amount,
            'adjustment_amount' => (float) $voucher->adjustment_amount,
            'balance_amount' => (float) $voucher->balance_amount,
            'settlement_type' => $voucher->settlement_type,
            'status' => $voucher->status,
            'reason' => $voucher->reason,
            'remarks' => $voucher->remarks,
            'created_by' => optional($voucher->creator)->name,
            'items' => $voucher->items->map(fn ($item) => [
                'id' => $item->id,
                'sales_item_id' => $item->sales_item_id,
                'product_id' => $item->product_id,
                'product' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'product_variant_id' => $item->product_variant_id,
                'batch_id' => $item->batch_id,
                'unit_id' => $item->unit_id,
                'quantity' => (float) $item->quantity,
                'selling_rate' => (float) $item->selling_rate,
                'discount_amount' => (float) $item->discount_amount,
                'taxable_amount' => (float) $item->taxable_amount,
                'gst_rate' => (float) $item->gst_rate,
                'cess_rate' => (float) $item->cess_rate,
                'line_total' => (float) $item->line_total,
                'return_reason' => $item->return_reason,
                'condition_status' => $item->condition_status,
                'restock_status' => $item->restock_status,
            ])->values(),
            'refunds' => $voucher->refunds->map(fn ($refund) => [
                'id' => $refund->id,
                'payment_method_id' => $refund->payment_method_id,
                'payment_method' => optional($refund->method)->name,
                'amount' => (float) $refund->amount,
                'refund_date' => optional($refund->refund_date)->format('Y-m-d'),
                'reference_number' => $refund->reference_number,
                'notes' => $refund->notes,
            ])->values(),
        ];
    }

    private function calculateTotals(array $data, ?int $currentReturnId = null): array
    {
        $items = [];
        $subtotal = $discount = $taxable = $cgst = $sgst = $igst = $cess = 0.0;

        foreach ($data['items'] as $item) {
            $line = $data['return_type'] === 'against_sale'
                ? $this->linkedLine($item, $currentReturnId)
                : $this->calculator->calculateLineTax([
                    'quantity' => $item['quantity'],
                    'selling_rate' => $item['selling_rate'],
                    'discount_type' => 'amount',
                    'discount_value' => $item['discount_amount'] ?? 0,
                    'gst_rate' => $item['gst_rate'] ?? 0,
                    'cess_rate' => $item['cess_rate'] ?? 0,
                ], $data['tax_type'], 'tax_invoice');

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
        $refund = round(collect($data['refunds'] ?? [])->sum(fn ($refund) => (float) ($refund['amount'] ?? 0)), 2);
        $adjustment = in_array($data['settlement_type'], ['invoice_adjustment', 'customer_credit'], true) ? max(0, $rounded - $refund) : 0;

        if ($refund > $rounded) {
            throw ValidationException::withMessages(['refunds' => 'Refund cannot exceed credit note amount.']);
        }

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
            'refund_amount' => $refund,
            'adjustment_amount' => round($adjustment, 2),
            'balance_amount' => round(max(0, $rounded - $refund - $adjustment), 2),
        ];
    }

    private function linkedLine(array $item, ?int $currentReturnId): array
    {
        $salesItem = SalesItem::query()->findOrFail($item['sales_item_id']);
        $baseQty = (float) $salesItem->quantity + (float) $salesItem->free_quantity;
        $available = $baseQty - $this->returnedQuantity($salesItem->id, $currentReturnId);
        $quantity = (float) $item['quantity'];

        if ($quantity > $available) {
            throw ValidationException::withMessages(['quantity' => 'Return quantity cannot exceed available return quantity.']);
        }

        $ratio = $baseQty > 0 ? $quantity / $baseQty : 0;

        return [
            'gross_amount' => round((float) $salesItem->selling_rate * $quantity, 2),
            'discount_amount' => round((float) $salesItem->discount_amount * $ratio, 2),
            'taxable_amount' => round((float) $salesItem->taxable_amount * $ratio, 2),
            'gst_rate' => (float) $salesItem->gst_rate,
            'cgst_rate' => (float) $salesItem->cgst_rate,
            'sgst_rate' => (float) $salesItem->sgst_rate,
            'igst_rate' => (float) $salesItem->igst_rate,
            'cgst_amount' => round((float) $salesItem->cgst_amount * $ratio, 2),
            'sgst_amount' => round((float) $salesItem->sgst_amount * $ratio, 2),
            'igst_amount' => round((float) $salesItem->igst_amount * $ratio, 2),
            'cess_rate' => (float) $salesItem->cess_rate,
            'cess_amount' => round((float) $salesItem->cess_amount * $ratio, 2),
            'line_total' => round((float) $salesItem->line_total * $ratio, 2),
            'selling_rate' => (float) $salesItem->selling_rate,
            'product_id' => $salesItem->product_id,
            'product_variant_id' => $salesItem->product_variant_id,
            'batch_id' => $salesItem->batch_id,
            'unit_id' => $salesItem->unit_id,
            'product_name_snapshot' => $salesItem->product_name_snapshot,
            'sku_snapshot' => $salesItem->sku_snapshot,
            'hsn_code_snapshot' => $salesItem->hsn_code_snapshot,
        ];
    }

    private function voucherAttributes(int $businessId, array $data, array $totals, array $extra = []): array
    {
        return array_merge([
            'business_id' => $businessId,
            'branch_id' => $data['branch_id'],
            'warehouse_id' => $data['warehouse_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'sales_voucher_id' => $data['return_type'] === 'against_sale' ? ($data['sales_voucher_id'] ?? null) : null,
            'return_date' => $data['return_date'],
            'return_type' => $data['return_type'],
            'tax_type' => $data['tax_type'],
            'place_of_supply_state_id' => $data['place_of_supply_state_id'] ?? null,
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'taxable_amount' => $totals['taxable_amount'],
            'cgst_amount' => $totals['cgst_amount'],
            'sgst_amount' => $totals['sgst_amount'],
            'igst_amount' => $totals['igst_amount'],
            'cess_amount' => $totals['cess_amount'],
            'round_off' => $totals['round_off'],
            'grand_total' => $totals['grand_total'],
            'refund_amount' => $totals['refund_amount'],
            'adjustment_amount' => $totals['adjustment_amount'],
            'balance_amount' => $totals['balance_amount'],
            'settlement_type' => $data['settlement_type'],
            'reason' => $data['reason'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ], $extra);
    }

    private function syncItems(SalesReturnVoucher $voucher, array $items): void
    {
        foreach ($items as $item) {
            $product = Product::query()->findOrFail($item['product_id']);
            $voucher->items()->create([
                'sales_item_id' => $item['sales_item_id'] ?? null,
                'product_id' => $item['product_id'],
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'batch_id' => $item['batch_id'] ?? null,
                'unit_id' => $item['unit_id'] ?? null,
                'product_name_snapshot' => $item['product_name_snapshot'] ?? ($product->invoice_description ?: $product->name),
                'sku_snapshot' => $item['sku_snapshot'] ?? $product->sku,
                'hsn_code_snapshot' => $item['hsn_code_snapshot'] ?? ($product->hsn_code ?: $product->hsn),
                'quantity' => $item['quantity'],
                'selling_rate' => $item['selling_rate'],
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
                'return_reason' => $item['return_reason'] ?? null,
                'condition_status' => $item['condition_status'] ?? null,
                'restock_status' => $item['restock_status'],
            ]);
        }
    }

    private function syncRefunds(SalesReturnVoucher $voucher, array $refunds): void
    {
        foreach ($refunds as $refund) {
            $voucher->refunds()->create([
                'business_id' => $voucher->business_id,
                'payment_method_id' => $refund['payment_method_id'],
                'amount' => $refund['amount'],
                'refund_date' => $refund['refund_date'] ?? $voucher->return_date,
                'reference_number' => $refund['reference_number'] ?? null,
                'notes' => $refund['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
        }
    }

    private function validateOwnership(int $businessId, array $data, ?int $currentReturnId = null): void
    {
        Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        Warehouse::query()->where('business_id', $businessId)->where('id', $data['warehouse_id'])->firstOrFail();

        if (!empty($data['customer_id'])) {
            Customer::query()->where('business_id', $businessId)->where('id', $data['customer_id'])->firstOrFail();
        }

        $sale = null;
        if ($data['return_type'] === 'against_sale') {
            $sale = SalesVoucher::query()
                ->where('business_id', $businessId)
                ->whereIn('status', ['confirmed', 'approved'])
                ->where('id', $data['sales_voucher_id'])
                ->firstOrFail();

            if ((int) ($sale->branch_id ?: 0) !== (int) ($data['branch_id'] ?: 0) || (int) ($sale->warehouse_id ?: 0) !== (int) ($data['warehouse_id'] ?: 0)) {
                throw ValidationException::withMessages(['sales_voucher_id' => 'Branch and warehouse must match original invoice.']);
            }

            if ((int) ($sale->customer_id ?: 0) !== (int) ($data['customer_id'] ?: 0)) {
                throw ValidationException::withMessages(['customer_id' => 'Customer must match original invoice.']);
            }
        }

        foreach ($data['items'] as $index => $item) {
            if ($sale) {
                $salesItem = SalesItem::query()->where('sales_voucher_id', $sale->id)->where('id', $item['sales_item_id'])->firstOrFail();
                foreach (['product_id', 'product_variant_id', 'batch_id'] as $field) {
                    if ((int) ($salesItem->{$field} ?: 0) !== (int) ($item[$field] ?? 0)) {
                        throw ValidationException::withMessages(["items.$index.$field" => 'Returned item must match original invoice line.']);
                    }
                }
                continue;
            }

            $product = Product::query()
                ->where('id', $item['product_id'])
                ->where('status', 'active')
                ->where(function (Builder $q) use ($businessId) {
                    $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
                })
                ->firstOrFail();

            if (!empty($item['product_variant_id'])) {
                ProductVariantItem::query()->where('business_id', $businessId)->where('product_id', $product->id)->where('id', $item['product_variant_id'])->firstOrFail();
            }

            if (($product->batch_required || in_array($product->tracking_type, ['batch', 'batch_expiry'], true)) && empty($item['batch_id'])) {
                throw ValidationException::withMessages(["items.$index.batch_id" => 'Batch is required for this product.']);
            }

            if (!empty($item['batch_id'])) {
                ProductBatch::query()->where('business_id', $businessId)->where('product_id', $product->id)->where('id', $item['batch_id'])->firstOrFail();
            }
        }

        foreach ($data['refunds'] ?? [] as $refund) {
            PaymentMethod::query()
                ->where('id', $refund['payment_method_id'])
                ->where(function (Builder $q) use ($businessId) {
                    $q->whereNull('business_id')->orWhere('business_id', $businessId);
                })
                ->firstOrFail();
        }
    }

    private function returnedQuantity(int $salesItemId, ?int $excludeReturnId = null): float
    {
        return (float) DB::table('sales_return_items')
            ->join('sales_return_vouchers', 'sales_return_vouchers.id', '=', 'sales_return_items.sales_return_voucher_id')
            ->where('sales_return_items.sales_item_id', $salesItemId)
            ->whereIn('sales_return_vouchers.status', ['confirmed', 'approved'])
            ->when($excludeReturnId, fn ($q) => $q->where('sales_return_vouchers.id', '!=', $excludeReturnId))
            ->sum('sales_return_items.quantity');
    }

    private function hasStockPosting(SalesReturnVoucher $voucher): bool
    {
        return DB::table('stock_ledgers')
            ->where('business_id', $voucher->business_id)
            ->where('reference_type', SalesReturnVoucher::class)
            ->where('reference_id', $voucher->id)
            ->exists();
    }

    private function assertBusiness(SalesReturnVoucher $voucher): void
    {
        abort_unless((int) $voucher->business_id === AppController::businessId(), 404);
    }

    private function fresh(SalesReturnVoucher $voucher): SalesReturnVoucher
    {
        return $voucher->fresh(['customer', 'sale', 'branch', 'warehouse', 'creator', 'items.product', 'items.variant', 'items.batch', 'refunds.method']);
    }
}
