<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesReturnVoucher;
use App\Models\SalesVoucher;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\ProductBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;


class SalesReturnService
{
    private SalesInvoiceNumberService $numbers;
    private StockService $stock;
    private SalesService $sales;

    public function __construct(SalesInvoiceNumberService $numbers, StockService $stock, SalesService $sales)
    {
        $this->numbers = $numbers;
        $this->stock = $stock;
        $this->sales = $sales;
    }

    public function list(array $filters = [])
    {
        $businessId = AppController::businessId();
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $this->returnQuery($filters)->paginate($perPage);
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

    public function post(
        SalesReturnVoucher $voucher,
        string $status = 'approved'
    ): SalesReturnVoucher {
        return DB::transaction(function () use ($voucher, $status) {

            $this->assertBusiness($voucher);

            $voucher = SalesReturnVoucher::query()
            ->whereKey($voucher->id)
            ->lockForUpdate()
            ->firstOrFail();

            if ($voucher->status !== 'draft') {
                return $this->fresh($voucher);
            }

        /*
         * Load original sale too, because return stock cost
         * should come from the original sale ledger.
         */
        $voucher->load([
            'sale',
            'items.product',
            'items.salesItem',
        ]);

        foreach ($voucher->items as $item) {
            if (VoucherSerialService::tracked($item->product)) {
                throw ValidationException::withMessages(['serials' => 'Serial-tracked returns require serial-specific stock and history handling; this return workflow does not yet support them.']);
            }

            /*
             * Services / non-stock items do not affect inventory.
             */
            if (
                $item->product->product_type === 'service'
                ||
                $item->product->item_type === 'non_stock'
            ) {
                continue;
            }

            /*
             * These return decisions intentionally do not put
             * stock back into inventory.
             */
            if (
                in_array(
                    $item->restock_status,
                    [
                        'non_restockable',
                        'scrap',
                        'vendor_return',
                    ],
                    true
                )
            ) {
                AuditLogger::record([
                    'module_name' => 'Sales Return',
                    'record_id' => $voucher->id,
                    'action_type' => 'Non Restockable',
                    'business_id' => $voucher->business_id,
                    'summary' => 'Returned item kept out of stock ledger',
                ]);

                continue;
            }

            /*
             * Restore exact original batch cost.
             *
             * For Automatic FEFO sales one SalesItem may be split
             * across multiple batches, therefore salesItem->cost_rate
             * is not reliable enough.
             */
            $unitCost = $this->originalSaleBatchCost(
                $voucher,
                $item
            );

            /*
             * Put returned stock back into the exact original batch.
             */
            $this->stock->increaseStock([
                'business_id' => $voucher->business_id,
                'branch_id' => $voucher->branch_id,
                'warehouse_id' => $voucher->warehouse_id,

                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'batch_id' => $item->batch_id,

                'stock_status' => [
                    'damaged_stock' => 'damaged',
                    'expired_stock' => 'expired',
                    'quarantine_stock' => 'quarantined',
                ][$item->restock_status] ?? 'saleable',

                'transaction_type' => 'sales_return',

                'reference_type' => SalesReturnVoucher::class,
                'reference_id' => $voucher->id,

                'quantity' => $item->quantity,
                'unit_cost' => $unitCost,

                'transaction_date' => $voucher->return_date,

                'remarks' => 'Sales return '
                . $voucher->credit_note_number
                . ' against '
                . ($voucher->sale?->invoice_number ?? 'original sale'),
            ]);
        }

        /*
         * Mark sales return as posted.
         */
        $voucher->update([
            'status' => $status === 'confirmed'
            ? 'confirmed'
            : 'approved',

            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        /*
         * Adjust original invoice balance where applicable.
         */
        $this->applyInvoiceAdjustment($voucher);

        /*
         * Accounting posting.
         */
        if (Schema::hasTable('journal_vouchers')) {
            app(AccountingPostingService::class)
            ->postSalesReturn(
                $voucher->fresh(['items'])
            );
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

public function addRefund(SalesReturnVoucher $voucher, array $refund): SalesReturnVoucher
{
    return DB::transaction(function () use ($voucher, $refund) {
        $this->assertBusiness($voucher);

        if (!in_array($voucher->status, ['confirmed', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => 'Refunds can be added only after approval.']);
        }

        PaymentMethod::query()
        ->where('id', $refund['payment_method_id'])
        ->where(function (Builder $q) use ($voucher) {
            $q->whereNull('business_id')->orWhere('business_id', $voucher->business_id);
        })
        ->firstOrFail();

        $amount = round((float) $refund['amount'], 2);
        if ($amount > (float) $voucher->balance_amount) {
            throw ValidationException::withMessages(['amount' => 'Refund cannot exceed remaining credit.']);
        }

        $voucher->refunds()->create([
            'business_id' => $voucher->business_id,
            'payment_method_id' => $refund['payment_method_id'],
            'amount' => $amount,
            'refund_date' => $refund['refund_date'] ?? now()->toDateString(),
            'reference_number' => $refund['reference_number'] ?? null,
            'notes' => $refund['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);

        $refundTotal = round((float) $voucher->refunds()->sum('amount'), 2);
        $adjustment = $voucher->settlement_type === 'invoice_adjustment' ? (float) $voucher->adjustment_amount : 0.0;

        $voucher->update([
            'refund_amount' => $refundTotal,
            'adjustment_amount' => round($adjustment, 2),
            'balance_amount' => round(max(0, (float) $voucher->grand_total - $refundTotal - $adjustment), 2),
        ]);

        AuditLogger::record([
            'module_name' => 'Sales Return',
            'record_id' => $voucher->id,
            'action_type' => 'Refund Added',
            'business_id' => $voucher->business_id,
            'summary' => 'Refund added against sales return credit note',
        ]);

        return $this->fresh($voucher);
    });
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
        $this->restoreInvoiceAdjustment($voucher);

        return $this->fresh($voucher);
    });
}

public function references(): array
{
    return $this->sales->references();
}

public function searchSales(string $search, array $filters = [])
{
    $query = SalesVoucher::query()
    ->with(['customer', 'branch', 'warehouse'])
    ->whereIn('status', ['confirmed', 'approved'])
    ->when(!empty($filters['customer_id']), fn (Builder $q) => $q->where('customer_id', $filters['customer_id']))
    ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
    ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']));

    AppController::applyTenantScope($query, 'sales_vouchers');

    return $query->where(function (Builder $q) use ($search) {
        $like = '%' . $search . '%';
        $q->where('invoice_number', 'like', $like)
        ->orWhere('voucher_number', 'like', $like)
        ->orWhere('customer_name_snapshot', 'like', $like)
        ->orWhere('customer_mobile_snapshot', 'like', $like)
        ->orWhereHas('customer', function (Builder $customer) use ($like) {
            $customer->where('customer_name', 'like', $like)
            ->orWhere('customer_code', 'like', $like)
            ->orWhere('mobile', 'like', $like)
            ->orWhere('gstin', 'like', $like);
        });
    })
    ->latest('id')
    ->limit(20)
    ->get()
    ->filter(fn (SalesVoucher $voucher) => $this->invoiceHasReturnableItems($voucher->id))
    ->values()
    ->map(fn (SalesVoucher $voucher) => [
        'id' => $voucher->id,
        'invoice_number' => $voucher->invoice_number,
        'invoice_date' => optional($voucher->invoice_date)->format('Y-m-d'),
        'customer_id' => $voucher->customer_id,
        'customer' => $voucher->customer_name_snapshot ?: optional($voucher->customer)->customer_name,
        'mobile' => $voucher->customer_mobile_snapshot ?: optional($voucher->customer)->mobile,
        'branch_id' => $voucher->branch_id,
        'branch' => optional($voucher->branch)->name,
        'warehouse_id' => $voucher->warehouse_id,
        'warehouse' => optional($voucher->warehouse)->name,
        'tax_type' => $voucher->tax_type,
        'place_of_supply_state_id' => $voucher->place_of_supply_state_id,
        'grand_total' => (float) $voucher->grand_total,
        'paid_amount' => (float) $voucher->paid_amount,
        'balance_amount' => (float) $voucher->balance_amount,
        'payment_status' => $voucher->payment_status,
        'existing_return_total' => (float) $voucher->returns()->whereIn('status', ['confirmed', 'approved'])->sum('grand_total'),
        'returnable_status' => 'Returnable',
    ]);
}

public function saleItems(int $saleId)
{
    $sale = SalesVoucher::query()
    ->with(['items.product.images', 'items.variant', 'items.batch', 'items.unit'])
    ->whereIn('status', ['confirmed', 'approved'])
    ->where('id', $saleId)
    ->tap(fn (Builder $q) => AppController::applyTenantScope($q, 'sales_vouchers'))
    ->firstOrFail();

    return $sale->items
    ->flatMap(function (SalesItem $item) {

        $image = $item->product
        ? $item->product->images->sortByDesc('is_primary')->first()
        : null;

            /*
             * For batch-tracked sales, SalesService stores the actual
             * FEFO allocation inside sales_items.batch_allocations.
             *
             * Example:
             * [
             *   ['batch_id' => 2, 'quantity' => 5],
             *   ['batch_id' => 1, 'quantity' => 2],
             * ]
             */
            $allocations = collect($item->batch_allocations ?? [])
            ->filter(function ($allocation) {
                return !empty($allocation['batch_id'])
                && (float) ($allocation['quantity'] ?? 0) > 0;
            })
            ->values();

            /*
             * Old/manual invoices may have batch_id directly on sales_items.
             * Keep backwards compatibility.
             */
            if ($allocations->isEmpty()) {
                $allocations = collect([
                    [
                        'batch_id' => $item->batch_id,
                        'quantity' => (float) $item->quantity + (float) $item->free_quantity,
                    ],
                ]);
            }

            return $allocations->map(function ($allocation) use ($item, $image) {

                $batchId = !empty($allocation['batch_id'])
                ? (int) $allocation['batch_id']
                : null;

                $soldQty = (float) ($allocation['quantity'] ?? 0);

                $returned = $this->returnedQuantity(
                    $item->id,
                    null,
                    $batchId
                );

                $available = max(0, $soldQty - $returned);

                $batch = $batchId
                ? ProductBatch::query()->find($batchId)
                : null;

                $baseQty = (float) $item->quantity + (float) $item->free_quantity;

                return [
                    'sales_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product' => $item->product_name_snapshot,
                    'sku' => $item->sku_snapshot,
                    'barcode' => $item->barcode_snapshot
                    ?: (optional($item->product)->primary_barcode
                        ?: optional($item->product)->barcode),
                    'hsn_code' => $item->hsn_code_snapshot,
                    'image_url' => $this->imageUrl(optional($image)->image_path),

                    'product_variant_id' => $item->product_variant_id,
                    'variant' => optional($item->variant)->sku,

                    'batch_id' => $batchId,
                    'batch' => optional($batch)->batch_no
                    ?: optional($batch)->batch_number,

                    'expiry_date' => optional($batch)->expiry_date
                    ? optional($batch->expiry_date)->format('Y-m-d')
                    : null,

                    'unit_id' => $item->unit_id,
                    'unit' => optional($item->unit)->code
                    ?: optional($item->unit)->name,

                    /*
                     * IMPORTANT:
                     * sold_quantity is batch-specific now.
                     */
                    'sold_quantity' => $soldQty,
                    'previously_returned' => $returned,
                    'available_quantity' => $available,

                    'quantity' => 0,

                    'selling_rate' => (float) $item->selling_rate,
                    'discount_amount' => $baseQty > 0
                    ? round((float) $item->discount_amount * ($soldQty / $baseQty), 2)
                    : 0,

                    'gst_rate' => (float) $item->gst_rate,
                    'cgst_rate' => (float) $item->cgst_rate,
                    'sgst_rate' => (float) $item->sgst_rate,
                    'igst_rate' => (float) $item->igst_rate,

                    'cgst_amount' => $baseQty > 0
                    ? round((float) $item->cgst_amount * ($soldQty / $baseQty), 2)
                    : 0,

                    'sgst_amount' => $baseQty > 0
                    ? round((float) $item->sgst_amount * ($soldQty / $baseQty), 2)
                    : 0,

                    'igst_amount' => $baseQty > 0
                    ? round((float) $item->igst_amount * ($soldQty / $baseQty), 2)
                    : 0,

                    'cess_rate' => (float) $item->cess_rate,

                    'cess_amount' => $baseQty > 0
                    ? round((float) $item->cess_amount * ($soldQty / $baseQty), 2)
                    : 0,

                    'taxable_amount' => $baseQty > 0
                    ? round((float) $item->taxable_amount * ($soldQty / $baseQty), 2)
                    : 0,

                    'line_total' => $baseQty > 0
                    ? round((float) $item->line_total * ($soldQty / $baseQty), 2)
                    : 0,

                    'return_line_total' => $baseQty > 0
                    ? round((float) $item->line_total * ($available / $baseQty), 2)
                    : 0,

                    'condition_status' => 'good',
                    'restock_status' => 'restock',
                ];
            });
        })
->filter(fn (array $item) => $item['available_quantity'] > 0)
->values();
}

public function exportRows(array $filters = [])
{
    return $this->returnQuery($filters)->get()->map(function (SalesReturnVoucher $voucher) {
        return [
            $voucher->credit_note_number,
            optional($voucher->return_date)->format('Y-m-d'),
            optional($voucher->sale)->invoice_number,
            optional(optional($voucher->sale)->invoice_date)->format('Y-m-d'),
            optional($voucher->customer)->customer_name ?: optional($voucher->sale)->customer_name_snapshot,
            optional($voucher->customer)->gstin,
            optional($voucher->branch)->name,
            optional($voucher->warehouse)->name,
            $voucher->return_type,
            (float) $voucher->taxable_amount,
            (float) $voucher->cgst_amount,
            (float) $voucher->sgst_amount,
            (float) $voucher->igst_amount,
            (float) $voucher->cess_amount,
            (float) $voucher->grand_total,
            (float) $voucher->refund_amount,
            (float) $voucher->balance_amount,
            $voucher->settlement_type,
            $voucher->status,
            $voucher->reason,
            optional($voucher->creator)->name,
            optional($voucher->approver)->name,
        ];
    });
}

private function returnQuery(array $filters = []): Builder
{
    $query = SalesReturnVoucher::query()
    ->with(['customer', 'sale', 'branch', 'warehouse', 'creator', 'approver', 'items.product', 'items.variant', 'items.batch', 'items.unit', 'refunds.method'])
    ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('return_date', '>=', $filters['date_from']))
    ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('return_date', '<=', $filters['date_to']))
    ->when(!empty($filters['customer_id']), fn (Builder $q) => $q->where('customer_id', $filters['customer_id']))
    ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
    ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
    ->when(!empty($filters['return_type']), fn (Builder $q) => $q->where('return_type', $filters['return_type']))
    ->when(!empty($filters['settlement_type']), fn (Builder $q) => $q->where('settlement_type', $filters['settlement_type']))
    ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
    ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
        $like = '%' . trim((string) $filters['search']) . '%';
        $q->where(function (Builder $inner) use ($like) {
            $inner->where('credit_note_number', 'like', $like)
            ->orWhere('voucher_number', 'like', $like)
            ->orWhere('reason', 'like', $like)
            ->orWhereHas('sale', fn (Builder $sale) => $sale->where('invoice_number', 'like', $like)->orWhere('voucher_number', 'like', $like))
            ->orWhereHas('customer', fn (Builder $customer) => $customer->where('customer_name', 'like', $like)->orWhere('mobile', 'like', $like)->orWhere('gstin', 'like', $like));
        });
    });

    AppController::applyTenantScope($query, 'sales_return_vouchers');

    return $query->latest('id');
}

public function printHtml(SalesReturnVoucher $voucher): string
{
    $voucher = $this->fresh($voucher);
    $company = Schema::hasTable('companies') ? DB::table('companies')->where('id', $voucher->business_id)->first() : null;
    $money = fn ($value) => '&#8377;' . number_format((float) $value, 2, '.', ',');

    $rows = $voucher->items->map(fn ($item) => '<tr><td>' . e($item->product_name_snapshot) . '<br><small>' . e($item->sku_snapshot) . '</small></td><td>' . e($item->hsn_code_snapshot) . '</td><td>' . e(optional($item->batch)->batch_no ?: optional($item->batch)->batch_number ?: '-') . '</td><td class="right">' . number_format((float) $item->quantity, 3) . '</td><td class="right">' . $money($item->selling_rate) . '</td><td class="right">' . $money($item->discount_amount) . '</td><td class="right">' . $money($item->taxable_amount) . '</td><td class="right">' . $money($item->cgst_amount) . '</td><td class="right">' . $money($item->sgst_amount) . '</td><td class="right">' . $money($item->igst_amount) . '</td><td class="right">' . $money($item->cess_amount) . '</td><td class="right">' . $money($item->line_total) . '</td></tr>')->implode('');
    $refundRows = $voucher->refunds->map(fn ($refund) => '<tr><td>' . e(optional($refund->method)->name) . '</td><td>' . e(optional($refund->refund_date)->format('Y-m-d')) . '</td><td>' . e($refund->reference_number ?: '-') . '</td><td class="right">' . $money($refund->amount) . '</td></tr>')->implode('');

    return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($voucher->credit_note_number) . '</title><style>body{font-family:Arial,sans-serif;margin:28px;color:#172033}.top{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #172033;padding-bottom:14px}.muted,small{color:#667085}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:18px 0}table{width:100%;border-collapse:collapse;margin-top:14px}th,td{border:1px solid #d0d5dd;padding:8px;font-size:12px;text-align:left}th{background:#f2f4f7}.right{text-align:right}.totals{margin-left:auto;width:320px}.signature{margin-top:54px;text-align:right}@media print{button{display:none}body{margin:0}.no-print{display:none}}</style></head><body><button class="no-print" onclick="window.print()">Print</button><div class="top"><div><h2>GST Credit Note / Sales Return</h2><strong>' . e($company->name ?? 'Bill IQ') . '</strong><br><span class="muted">' . e($company->address ?? '') . '</span><br><span>GSTIN: ' . e($company->gstin ?? '-') . '</span></div><div><strong>Credit Note:</strong> ' . e($voucher->credit_note_number) . '<br><strong>Return Date:</strong> ' . e(optional($voucher->return_date)->format('Y-m-d')) . '<br><strong>Original Invoice:</strong> ' . e(optional($voucher->sale)->invoice_number ?: '-') . '<br><strong>Invoice Date:</strong> ' . e(optional(optional($voucher->sale)->invoice_date)->format('Y-m-d') ?: '-') . '</div></div><div class="grid"><div><h4>Customer</h4>' . e(optional($voucher->customer)->customer_name ?: optional($voucher->sale)->customer_name_snapshot ?: 'Walk-in Customer') . '<br>GSTIN: ' . e(optional($voucher->customer)->gstin ?: '-') . '<br>' . e(optional($voucher->customer)->billing_address ?: '') . '</div><div><h4>Return Details</h4>Supply: ' . e($voucher->tax_type) . '<br>Settlement: ' . e($voucher->settlement_type) . '<br>Status: ' . e($voucher->status) . '<br>Reason: ' . e($voucher->reason ?: '-') . '</div></div><table><thead><tr><th>Product</th><th>HSN</th><th>Batch</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Discount</th><th class="right">Taxable</th><th class="right">CGST</th><th class="right">SGST</th><th class="right">IGST</th><th class="right">Cess</th><th class="right">Credit</th></tr></thead><tbody>' . $rows . '</tbody></table><table class="totals"><tr><th>Taxable Credit</th><td class="right">' . $money($voucher->taxable_amount) . '</td></tr><tr><th>CGST Reversal</th><td class="right">' . $money($voucher->cgst_amount) . '</td></tr><tr><th>SGST Reversal</th><td class="right">' . $money($voucher->sgst_amount) . '</td></tr><tr><th>IGST Reversal</th><td class="right">' . $money($voucher->igst_amount) . '</td></tr><tr><th>Cess Reversal</th><td class="right">' . $money($voucher->cess_amount) . '</td></tr><tr><th>Total Credit</th><td class="right">' . $money($voucher->grand_total) . '</td></tr><tr><th>Refund</th><td class="right">' . $money($voucher->refund_amount) . '</td></tr><tr><th>Balance/Credit</th><td class="right">' . $money($voucher->balance_amount) . '</td></tr></table><h4>Refund Details</h4><table><thead><tr><th>Mode</th><th>Date</th><th>Reference</th><th class="right">Amount</th></tr></thead><tbody>' . ($refundRows ?: '<tr><td colspan="4" class="muted">No refund has been added. The remaining amount will be kept as customer credit.</td></tr>') . '</tbody></table><p><strong>Remarks:</strong> ' . e($voucher->remarks ?: '-') . '</p><div class="signature">Authorized signature</div></body></html>';
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
        'invoice_date' => optional(optional($voucher->sale)->invoice_date)->format('Y-m-d'),
        'invoice_grand_total' => (float) optional($voucher->sale)->grand_total,
        'invoice_balance_amount' => (float) optional($voucher->sale)->balance_amount,
        'payment_status' => optional($voucher->sale)->payment_status,
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
            'hsn_code' => $item->hsn_code_snapshot,
            'barcode' => optional($item->product)->primary_barcode ?: optional($item->product)->barcode,
            'product_variant_id' => $item->product_variant_id,
            'variant' => optional($item->variant)->sku,
            'batch_id' => $item->batch_id,
            'batch' => optional($item->batch)->batch_no ?: optional($item->batch)->batch_number,
            'unit_id' => $item->unit_id,
            'unit' => optional($item->unit)->code ?: optional($item->unit)->name,
            'quantity' => (float) $item->quantity,
            'selling_rate' => (float) $item->selling_rate,
            'discount_amount' => (float) $item->discount_amount,
            'taxable_amount' => (float) $item->taxable_amount,
            'gst_rate' => (float) $item->gst_rate,
            'cgst_amount' => (float) $item->cgst_amount,
            'sgst_amount' => (float) $item->sgst_amount,
            'igst_amount' => (float) $item->igst_amount,
            'cess_rate' => (float) $item->cess_rate,
            'cess_amount' => (float) $item->cess_amount,
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
        $line = $this->linkedLine($item, $currentReturnId);

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
    $adjustment = 0.0;
    if (($data['settlement_type'] ?? null) === 'invoice_adjustment') {
        $sale = SalesVoucher::query()->findOrFail($data['sales_voucher_id']);
        $adjustment = min(max(0, (float) $sale->balance_amount), max(0, $rounded - $refund));

        if ($adjustment <= 0) {
            throw ValidationException::withMessages(['settlement_type' => 'Invoice balance adjustment is available only when the original invoice has outstanding balance.']);
        }
    }

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
    $salesItem = SalesItem::query()
    ->lockForUpdate()
    ->findOrFail($item['sales_item_id']);

    $baseQty = (float) $salesItem->quantity
    + (float) $salesItem->free_quantity;

    $requestedBatchId = !empty($item['batch_id'])
    ? (int) $item['batch_id']
    : null;

    $allocations = collect($salesItem->batch_allocations ?? [])
    ->filter(function ($allocation) {
        return !empty($allocation['batch_id'])
        && (float) ($allocation['quantity'] ?? 0) > 0;
    })
    ->values();

    /*
     * Batch allocation based invoice.
     */
    if ($allocations->isNotEmpty()) {

        if (!$requestedBatchId) {
            throw ValidationException::withMessages([
                'batch_id' => 'Original sales batch is required for this return.',
            ]);
        }

        $allocation = $allocations->first(function ($allocation) use ($requestedBatchId) {
            return (int) ($allocation['batch_id'] ?? 0) === $requestedBatchId;
        });

        if (!$allocation) {
            throw ValidationException::withMessages([
                'batch_id' => 'Selected batch was not used in the original invoice.',
            ]);
        }

        $batchSoldQty = (float) ($allocation['quantity'] ?? 0);

        $alreadyReturned = $this->returnedQuantity(
            $salesItem->id,
            $currentReturnId,
            $requestedBatchId
        );

        $available = max(0, $batchSoldQty - $alreadyReturned);
    } else {

        /*
         * Backwards compatibility for non-batch / old invoice lines.
         */
        $originalBatchId = $salesItem->batch_id
        ? (int) $salesItem->batch_id
        : null;

        if ($originalBatchId !== $requestedBatchId) {
            throw ValidationException::withMessages([
                'batch_id' => 'Returned item must match original invoice batch.',
            ]);
        }

        $alreadyReturned = $this->returnedQuantity(
            $salesItem->id,
            $currentReturnId,
            $originalBatchId
        );

        $available = max(0, $baseQty - $alreadyReturned);
    }

    $quantity = (float) $item['quantity'];

    if ($quantity <= 0 || $quantity > $available) {
        throw ValidationException::withMessages([
            'quantity' => 'Return quantity cannot exceed available return quantity for this batch.',
        ]);
    }

    $this->validateUnitQuantity($salesItem, $quantity);

    $ratio = $baseQty > 0
    ? $quantity / $baseQty
    : 0;

    return [
        'gross_amount' => round(
            (float) $salesItem->selling_rate * $quantity,
            2
        ),

        'discount_amount' => round(
            (float) $salesItem->discount_amount * $ratio,
            2
        ),

        'taxable_amount' => round(
            (float) $salesItem->taxable_amount * $ratio,
            2
        ),

        'gst_rate' => (float) $salesItem->gst_rate,
        'cgst_rate' => (float) $salesItem->cgst_rate,
        'sgst_rate' => (float) $salesItem->sgst_rate,
        'igst_rate' => (float) $salesItem->igst_rate,

        'cgst_amount' => round(
            (float) $salesItem->cgst_amount * $ratio,
            2
        ),

        'sgst_amount' => round(
            (float) $salesItem->sgst_amount * $ratio,
            2
        ),

        'igst_amount' => round(
            (float) $salesItem->igst_amount * $ratio,
            2
        ),

        'cess_rate' => (float) $salesItem->cess_rate,

        'cess_amount' => round(
            (float) $salesItem->cess_amount * $ratio,
            2
        ),

        'line_total' => round(
            (float) $salesItem->line_total * $ratio,
            2
        ),

        'selling_rate' => (float) $salesItem->selling_rate,

        'product_id' => $salesItem->product_id,
        'product_variant_id' => $salesItem->product_variant_id,

        /*
         * IMPORTANT:
         * save the actual returned original batch.
         */
        'batch_id' => $requestedBatchId,

        'unit_id' => $salesItem->unit_id,
        'product_name_snapshot' => $salesItem->product_name_snapshot,
        'sku_snapshot' => $salesItem->sku_snapshot,
        'hsn_code_snapshot' => $salesItem->hsn_code_snapshot,
    ];
}

private function applyInvoiceAdjustment(SalesReturnVoucher $voucher): void
{
    if ($voucher->settlement_type !== 'invoice_adjustment' || (float) $voucher->adjustment_amount <= 0) {
        return;
    }

    $sale = SalesVoucher::query()
    ->where('business_id', $voucher->business_id)
    ->where('id', $voucher->sales_voucher_id)
    ->lockForUpdate()
    ->first();

    if (!$sale) {
        return;
    }

    $balance = round(max(0, (float) $sale->balance_amount - (float) $voucher->adjustment_amount), 2);
    $sale->update([
        'balance_amount' => $balance,
        'payment_status' => $balance <= 0 ? 'paid' : ((float) $sale->paid_amount > 0 ? 'partial' : 'unpaid'),
    ]);
}

private function restoreInvoiceAdjustment(SalesReturnVoucher $voucher): void
{
    if ($voucher->settlement_type !== 'invoice_adjustment' || (float) $voucher->adjustment_amount <= 0) {
        return;
    }

    $sale = SalesVoucher::query()
    ->where('business_id', $voucher->business_id)
    ->where('id', $voucher->sales_voucher_id)
    ->lockForUpdate()
    ->first();

    if (!$sale) {
        return;
    }

    // Reopen the invoice by the reversed credit note, keeping other posted returns applied.
    $balance = round(max(0, (float) $sale->grand_total - (float) $sale->paid_amount - SalesReturnVoucher::invoiceAdjustmentTotal($voucher->business_id, $sale->id)), 2);
    $sale->update([
        'balance_amount' => $balance,
        'payment_status' => $balance <= 0 ? 'paid' : ((float) $sale->paid_amount > 0 ? 'partial' : 'unpaid'),
    ]);
}

private function voucherAttributes(int $businessId, array $data, array $totals, array $extra = []): array
{
    return array_merge([
        'business_id' => $businessId,
        'branch_id' => $data['branch_id'],
        'warehouse_id' => $data['warehouse_id'],
        'customer_id' => $data['customer_id'] ?? null,
        'sales_voucher_id' => $data['sales_voucher_id'],
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
    // Validate branch
    Branch::query()
    ->where('business_id', $businessId)
    ->where('id', $data['branch_id'])
    ->firstOrFail();

    // Validate warehouse belongs to selected branch
    Warehouse::query()
    ->where('business_id', $businessId)
    ->where('branch_id', $data['branch_id'])
    ->where('id', $data['warehouse_id'])
    ->firstOrFail();

    // Validate customer
    if (!empty($data['customer_id'])) {
        Customer::query()
        ->where('business_id', $businessId)
        ->where('id', $data['customer_id'])
        ->firstOrFail();
    }

    // Sales return must always be against an original sale
    if (($data['return_type'] ?? null) !== 'against_sale') {
        throw ValidationException::withMessages([
            'return_type' => 'Sales returns must be created against a completed sales invoice.',
        ]);
    }

    // Load original posted invoice
    $sale = SalesVoucher::query()
    ->where('business_id', $businessId)
    ->whereIn('status', ['confirmed', 'approved'])
    ->where('id', $data['sales_voucher_id'])
    ->firstOrFail();

    // Branch + warehouse must match original invoice
    if (
        (int) ($sale->branch_id ?: 0) !== (int) ($data['branch_id'] ?: 0)
        ||
        (int) ($sale->warehouse_id ?: 0) !== (int) ($data['warehouse_id'] ?: 0)
    ) {
        throw ValidationException::withMessages([
            'sales_voucher_id' => 'Branch and warehouse must match original invoice.',
        ]);
    }

    // Customer must match original invoice
    if (
        (int) ($sale->customer_id ?: 0)
        !==
        (int) ($data['customer_id'] ?? 0)
    ) {
        throw ValidationException::withMessages([
            'customer_id' => 'Customer must match original invoice.',
        ]);
    }

    // Return cannot be dated before original invoice
    if (
        !empty($data['return_date'])
        &&
        optional($sale->invoice_date)->format('Y-m-d')
        &&
        $data['return_date'] < $sale->invoice_date->format('Y-m-d')
    ) {
        throw ValidationException::withMessages([
            'return_date' => 'Return date cannot be earlier than original invoice date.',
        ]);
    }

    // Invoice adjustment only when original invoice has outstanding balance
    if (
        ($data['settlement_type'] ?? null) === 'invoice_adjustment'
        &&
        (float) $sale->balance_amount <= 0
    ) {
        throw ValidationException::withMessages([
            'settlement_type' => 'Invoice balance adjustment is available only when the original invoice has outstanding balance.',
        ]);
    }

    /*
     * Validate every returned line against the original sales item.
     *
     * IMPORTANT:
     * Automatic FEFO sales may have sales_items.batch_id = NULL.
     * Actual batches used in the sale are stored in
     * sales_items.batch_allocations.
     */
    foreach ($data['items'] as $index => $item) {

        $this->validateRestockDecision($item, $index);

        $salesItem = SalesItem::query()
        ->where('sales_voucher_id', $sale->id)
        ->where('id', $item['sales_item_id'])
        ->firstOrFail();

        /*
         * Product must match original invoice line.
         */
        if (
            (int) $salesItem->product_id
            !==
            (int) ($item['product_id'] ?? 0)
        ) {
            throw ValidationException::withMessages([
                "items.$index.product_id" => 'Returned product must match original invoice line.',
            ]);
        }

        /*
         * Variant must match original invoice line.
         */
        if (
            (int) ($salesItem->product_variant_id ?: 0)
            !==
            (int) ($item['product_variant_id'] ?? 0)
        ) {
            throw ValidationException::withMessages([
                "items.$index.product_variant_id" => 'Returned product variant must match original invoice line.',
            ]);
        }

        /*
         * Batch selected by Sales Return.
         */
        $requestedBatchId = !empty($item['batch_id'])
        ? (int) $item['batch_id']
        : null;

        /*
         * Actual FEFO allocations used during original sale.
         */
        $allocations = collect($salesItem->batch_allocations ?? [])
        ->filter(function ($allocation) {
            return !empty($allocation['batch_id'])
            && (float) ($allocation['quantity'] ?? 0) > 0;
        })
        ->values();

        /*
         * FEFO / multi-batch sale.
         *
         * The returned batch does NOT need to equal
         * sales_items.batch_id.
         *
         * It must exist inside batch_allocations.
         */
        if ($allocations->isNotEmpty()) {

            if (!$requestedBatchId) {
                throw ValidationException::withMessages([
                    "items.$index.batch_id" => 'Original sales batch is required for this return.',
                ]);
            }

            $validBatch = $allocations->contains(function ($allocation) use ($requestedBatchId) {
                return (int) ($allocation['batch_id'] ?? 0) === $requestedBatchId;
            });

            if (!$validBatch) {
                throw ValidationException::withMessages([
                    "items.$index.batch_id" => 'Returned batch must be one of the batches used in the original invoice.',
                ]);
            }

        } else {

            /*
             * Backwards compatibility:
             *
             * Old/manual invoice where batch was stored directly
             * on sales_items.batch_id.
             *
             * Non-batch items will have NULL on both sides.
             */
            $originalBatchId = $salesItem->batch_id
            ? (int) $salesItem->batch_id
            : null;

            if ($originalBatchId !== $requestedBatchId) {
                throw ValidationException::withMessages([
                    "items.$index.batch_id" => 'Returned batch must match original invoice line.',
                ]);
            }
        }
    }

    // Validate refund payment methods
    foreach ($data['refunds'] ?? [] as $index => $refund) {

        PaymentMethod::query()
        ->where('id', $refund['payment_method_id'])
        ->where(function (Builder $q) use ($businessId) {
            $q->whereNull('business_id')
            ->orWhere('business_id', $businessId);
        })
        ->firstOrFail();
    }
}

private function returnedQuantity(
    int $salesItemId,
    ?int $excludeReturnId = null,
    ?int $batchId = null
): float {
    return (float) DB::table('sales_return_items')
    ->join(
        'sales_return_vouchers',
        'sales_return_vouchers.id',
        '=',
        'sales_return_items.sales_return_voucher_id'
    )
    ->where('sales_return_items.sales_item_id', $salesItemId)
    ->whereIn('sales_return_vouchers.status', ['confirmed', 'approved'])
    ->when(
        $batchId !== null,
        fn ($q) => $q->where('sales_return_items.batch_id', $batchId),
        fn ($q) => $q->whereNull('sales_return_items.batch_id')
    )
    ->when(
        $excludeReturnId,
        fn ($q) => $q->where(
            'sales_return_vouchers.id',
            '!=',
            $excludeReturnId
        )
    )
    ->sum('sales_return_items.quantity');
}

private function invoiceHasReturnableItems(int $saleId): bool
{
    return SalesItem::query()
    ->where('sales_voucher_id', $saleId)
    ->get()
    ->contains(function (SalesItem $item) {

        $allocations = collect($item->batch_allocations ?? [])
        ->filter(fn ($allocation) =>
            !empty($allocation['batch_id'])
            && (float) ($allocation['quantity'] ?? 0) > 0
        );

        if ($allocations->isNotEmpty()) {
            return $allocations->contains(function ($allocation) use ($item) {

                $batchId = (int) $allocation['batch_id'];
                $sold = (float) $allocation['quantity'];

                $returned = $this->returnedQuantity(
                    $item->id,
                    null,
                    $batchId
                );

                return $sold > $returned;
            });
        }

        $sold = (float) $item->quantity
        + (float) $item->free_quantity;

        $returned = $this->returnedQuantity(
            $item->id,
            null,
            $item->batch_id ? (int) $item->batch_id : null
        );

        return $sold > $returned;
    });
}

private function validateRestockDecision(array $item, int $index): void
{
    $condition = $item['condition_status'] ?? 'good';
    $restock = $item['restock_status'] ?? 'restock';
    switch ($condition) {
        case 'expired':
        $allowed = ['expired_stock', 'non_restockable', 'scrap', 'vendor_return'];
        break;
        case 'damaged':
        case 'defective':
        case 'scrap':
        $allowed = ['damaged_stock', 'quarantine_stock', 'non_restockable', 'scrap', 'vendor_return'];
        break;
        case 'inspection_required':
        $allowed = ['quarantine_stock', 'non_restockable', 'scrap', 'vendor_return'];
        break;
        case 'opened':
        $allowed = ['restock', 'damaged_stock', 'quarantine_stock', 'non_restockable', 'scrap', 'vendor_return'];
        break;
        default:
        $allowed = ['restock', 'damaged_stock', 'expired_stock', 'non_restockable', 'scrap', 'vendor_return'];
        break;
    }

    if (!in_array($restock, $allowed, true)) {
        throw ValidationException::withMessages(["items.$index.restock_status" => 'Restock decision is not allowed for the selected condition.']);
    }
}

private function validateUnitQuantity(SalesItem $salesItem, float $quantity): void
{
    $unit = $salesItem->unit_id ? Unit::query()->find($salesItem->unit_id) : null;
    $unitText = strtolower(trim(($unit->code ?? '') . ' ' . ($unit->name ?? '')));
    $wholeUnits = ['pcs', 'piece', 'bottle', 'box', 'packet', 'pair', 'unit'];

    if ($unitText === '') {
        return;
    }

    foreach ($wholeUnits as $wholeUnit) {
        if (str_contains($unitText, $wholeUnit) && abs($quantity - round($quantity)) > 0.0001) {
            throw ValidationException::withMessages(['quantity' => 'This unit allows whole return quantities only.']);
        }
    }
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
    return $voucher->fresh(['customer', 'sale', 'branch', 'warehouse', 'creator', 'items.product.images', 'items.variant', 'items.batch', 'items.unit', 'refunds.method']);
}

private function imageUrl(?string $path): ?string
{
    if (!$path) {
        return null;
    }

    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
        return $path;
    }

    if (is_file(public_path($path))) {
        return asset($path);
    }

    if (is_file(storage_path('app/public/' . ltrim($path, '/')))) {
        return asset('storage/' . ltrim($path, '/'));
    }

    return asset($path);
}

private function originalSaleBatchCost(
    SalesReturnVoucher $voucher,
    $returnItem
): float {
    /*
     * Try to get the exact cost that was used when
     * the original invoice reduced this batch.
     */
    if (
        $voucher->sale
        &&
        $returnItem->batch_id
    ) {
        $ledger = DB::table('stock_ledgers')
        ->where(
            'business_id',
            $voucher->business_id
        )
        ->where(
            'branch_id',
            $voucher->branch_id
        )
        ->where(
            'warehouse_id',
            $voucher->warehouse_id
        )
        ->where(
            'product_id',
            $returnItem->product_id
        )
        ->when(
            !empty($returnItem->product_variant_id),

            fn ($q) => $q->where(
                'product_variant_id',
                $returnItem->product_variant_id
            ),

            fn ($q) => $q->whereNull(
                'product_variant_id'
            )
        )
        ->where(
            'batch_id',
            $returnItem->batch_id
        )
        ->where(
            'reference_type',
            SalesVoucher::class
        )
        ->where(
            'reference_id',
            $voucher->sale->id
        )
        ->where(
            'transaction_type',
            'sale'
        )
        ->orderByDesc('id')
        ->first();

        if (
            $ledger
            &&
            $ledger->unit_cost !== null
        ) {
            return (float) $ledger->unit_cost;
        }
    }

    /*
     * Fallback for old/non-batch data where exact
     * sale ledger cost is unavailable.
     */
    if (
        $returnItem->salesItem
        &&
        $returnItem->salesItem->cost_rate !== null
    ) {
        return (float) $returnItem->salesItem->cost_rate;
    }

    /*
     * Last fallback: current batch average cost.
     */
    return (float) $this->stock->getAverageCost([
        'business_id' => $voucher->business_id,
        'branch_id' => $voucher->branch_id,
        'warehouse_id' => $voucher->warehouse_id,
        'product_id' => $returnItem->product_id,
        'product_variant_id' => $returnItem->product_variant_id,
        'batch_id' => $returnItem->batch_id,
    ]);
}
}
