<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariantItem;
use App\Models\SalesVoucher;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SalesService
{
    private SalesCalculationService $calculator;
    private SalesInvoiceNumberService $numbers;
    private StockService $stock;
    private CustomerService $customers;

    public function __construct(SalesCalculationService $calculator, SalesInvoiceNumberService $numbers, StockService $stock, CustomerService $customers)
    {
        $this->calculator = $calculator;
        $this->numbers = $numbers;
        $this->stock = $stock;
        $this->customers = $customers;
    }

    public function list(array $filters = [])
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        $query = SalesVoucher::query()
            ->with(['customer', 'branch', 'warehouse', 'salesperson', 'creator', 'items', 'payments.method'])
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('invoice_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('invoice_date', '<=', $filters['date_to']))
            ->when(($filters['date'] ?? null) === 'today', fn (Builder $q) => $q->whereDate('invoice_date', now()->toDateString()))
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $like = '%' . $filters['search'] . '%';
                $q->where(fn (Builder $s) => $s->where('invoice_number', 'like', $like)
                    ->orWhere('voucher_number', 'like', $like)
                    ->orWhere('reference_number', 'like', $like)
                    ->orWhere('customer_name_snapshot', 'like', $like)
                    ->orWhere('customer_mobile_snapshot', 'like', $like)
                    ->orWhere('customer_gstin_snapshot', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('customer_name', 'like', $like)->orWhere('mobile', 'like', $like)->orWhere('gstin', 'like', $like)->orWhere('customer_code', 'like', $like)));
            })
            ->when(!empty($filters['customer_id']), fn (Builder $q) => $q->where('customer_id', $filters['customer_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['salesperson_id']), fn (Builder $q) => $q->where('salesperson_id', $filters['salesperson_id']))
            ->when(!empty($filters['sale_type']), fn (Builder $q) => $q->where('sale_type', $filters['sale_type']))
            ->when(!empty($filters['invoice_type']), fn (Builder $q) => $q->where('invoice_type', $filters['invoice_type']))
            ->when(!empty($filters['payment_status']), fn (Builder $q) => str_contains((string) $filters['payment_status'], ',') ? $q->whereIn('payment_status', explode(',', (string) $filters['payment_status'])) : $q->where('payment_status', $filters['payment_status']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['tax_type']), fn (Builder $q) => $q->where('tax_type', $filters['tax_type']));

        AppController::applyTenantScope($query, 'sales_vouchers');

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        return $query
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $data): SalesVoucher
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $this->prepareCustomer($data);
            $this->validateOwnership($businessId, $data);
            $totals = $this->calculator->calculateVoucherTotals($data);
            $this->calculator->validateSaleTotals($totals);
            $this->validateCreditLimit($businessId, $data, $totals);
            $numbers = $this->numbers->next($businessId, $data['branch_id'] ?? null);
            $status = $data['status'];

            $voucher = SalesVoucher::query()->create($this->voucherAttributes($businessId, $data, $totals, array_merge($numbers, [
                'status' => in_array($status, ['draft', 'hold'], true) ? $status : 'draft',
                'created_by' => Auth::id(),
            ])));

            $this->syncItems($voucher, $totals['items']);
            $this->syncPayments($voucher, $data['payments'] ?? []);

            if (in_array($status, ['confirmed', 'approved'], true)) {
                $this->post($voucher, $status);
            }

            AuditLogger::record([
                'module_name' => 'Sales',
                'record_id' => $voucher->id,
                'action_type' => 'Create',
                'business_id' => $businessId,
                'summary' => 'Sales voucher created',
            ]);

            return $this->fresh($voucher);
        });
    }

    public function update(SalesVoucher $voucher, array $data): SalesVoucher
    {
        return DB::transaction(function () use ($voucher, $data) {
            $this->assertBusiness($voucher);

            if (!in_array($voucher->status, ['draft', 'hold'], true)) {
                throw ValidationException::withMessages(['status' => 'Only draft or held sales can be edited.']);
            }

            $businessId = AppController::businessId();
            $this->prepareCustomer($data);
            $this->validateOwnership($businessId, $data, $voucher->id);
            $totals = $this->calculator->calculateVoucherTotals($data);
            $this->calculator->validateSaleTotals($totals);
            $this->validateCreditLimit($businessId, $data, $totals, $voucher->id);
            $status = $data['status'];

            $voucher->update($this->voucherAttributes($businessId, $data, $totals, [
                'status' => in_array($status, ['draft', 'hold'], true) ? $status : 'draft',
            ]));
            $voucher->items()->delete();
            $voucher->payments()->delete();
            $this->syncItems($voucher, $totals['items']);
            $this->syncPayments($voucher, $data['payments'] ?? []);

            if (in_array($status, ['confirmed', 'approved'], true)) {
                $this->post($voucher, $status);
            }

            return $this->fresh($voucher);
        });
    }

    public function duplicate(SalesVoucher $voucher): SalesVoucher
    {
        return DB::transaction(function () use ($voucher) {
            $this->assertBusiness($voucher);
            $numbers = $this->numbers->next($voucher->business_id, $voucher->branch_id);
            $copy = $voucher->replicate();
            $copy->voucher_number = $numbers['voucher_number'];
            $copy->invoice_number = $numbers['invoice_number'];
            $copy->status = 'draft';
            $copy->paid_amount = 0;
            $copy->balance_amount = $voucher->grand_total;
            $copy->change_returned = 0;
            $copy->payment_status = 'unpaid';
            $copy->approved_by = null;
            $copy->approved_at = null;
            $copy->cancelled_by = null;
            $copy->cancelled_at = null;
            $copy->created_by = Auth::id();
            $copy->save();

            foreach ($voucher->items as $item) {
                $copy->items()->create($item->replicate()->toArray());
            }

            return $this->fresh($copy);
        });
    }

    public function post(SalesVoucher $voucher, string $status = 'approved'): SalesVoucher
    {
        return DB::transaction(function () use ($voucher, $status) {
            $this->assertBusiness($voucher);

            if ($this->hasStockPosting($voucher)) {
                throw ValidationException::withMessages(['status' => 'Stock ledger already posted for this sale.']);
            }

            $voucher->load(['items.product']);

            foreach ($voucher->items as $item) {
                if ($item->product->product_type === 'service' || $item->product->item_type === 'non_stock' || !(bool) ($item->product->track_inventory ?? true)) {
                    continue;
                }

                $quantity = (float) $item->quantity + (float) $item->free_quantity;
                $this->stock->decreaseStock([
                    'business_id' => $voucher->business_id,
                    'branch_id' => $voucher->branch_id,
                    'warehouse_id' => $voucher->warehouse_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'batch_id' => $item->batch_id,
                    'transaction_type' => 'sale',
                    'reference_type' => SalesVoucher::class,
                    'reference_id' => $voucher->id,
                    'quantity' => $quantity,
                    'unit_cost' => $item->cost_rate ?: $this->stock->getAverageCost([
                        'business_id' => $voucher->business_id,
                        'branch_id' => $voucher->branch_id,
                        'warehouse_id' => $voucher->warehouse_id,
                        'product_id' => $item->product_id,
                        'product_variant_id' => $item->product_variant_id,
                        'batch_id' => $item->batch_id,
                    ]),
                    'transaction_date' => $voucher->invoice_date,
                    'remarks' => 'Sale ' . $voucher->invoice_number,
                ]);
            }

            $voucher->update([
                'status' => $status === 'confirmed' ? 'confirmed' : 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            if (\Illuminate\Support\Facades\Schema::hasTable('journal_vouchers')) {
                app(AccountingPostingService::class)->postSalesVoucher($voucher->fresh(['items']));
            }

            return $this->fresh($voucher);
        });
    }

    public function hold(SalesVoucher $voucher): SalesVoucher
    {
        $this->assertBusiness($voucher);

        if (!in_array($voucher->status, ['draft', 'hold'], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft invoices can be held.']);
        }

        $voucher->update(['status' => 'hold']);

        return $this->fresh($voucher);
    }

    public function addPayment(SalesVoucher $voucher, array $payment): SalesVoucher
    {
        return DB::transaction(function () use ($voucher, $payment) {
            $this->assertBusiness($voucher);

            if (!in_array($voucher->status, ['confirmed', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Payments can be added only after posting the invoice.']);
            }

            PaymentMethod::query()
                ->where('id', $payment['payment_method_id'])
                ->where(function (Builder $q) use ($voucher) {
                    $q->whereNull('business_id')->orWhere('business_id', $voucher->business_id);
                })
                ->firstOrFail();

            $amount = (float) $payment['amount'];
            if ($amount > (float) $voucher->balance_amount) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the invoice balance.']);
            }

            $voucher->payments()->create([
                'business_id' => $voucher->business_id,
                'payment_method_id' => $payment['payment_method_id'],
                'amount' => $amount,
                'reference_number' => $payment['reference_number'] ?? null,
                'payment_date' => $payment['payment_date'] ?? now()->toDateString(),
                'notes' => $payment['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $paid = round((float) $voucher->payments()->sum('amount'), 2);
            $voucher->update([
                'paid_amount' => min($paid, (float) $voucher->grand_total),
                'balance_amount' => round(max(0, (float) $voucher->grand_total - $paid), 2),
                'change_returned' => round(max(0, $paid - (float) $voucher->grand_total), 2),
                'payment_status' => $paid <= 0 ? 'unpaid' : ($paid >= (float) $voucher->grand_total ? 'paid' : 'partial'),
            ]);

            return $this->fresh($voucher);
        });
    }

    public function cancel(SalesVoucher $voucher, ?string $reason = null): SalesVoucher
    {
        $this->assertBusiness($voucher);

        if (!in_array($voucher->status, ['draft', 'hold'], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or held invoices can be cancelled.']);
        }

        $voucher->update([
            'status' => 'cancelled',
            'cancelled_by' => Auth::id(),
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return $this->fresh($voucher);
    }

    public function reverse(SalesVoucher $voucher, ?string $remarks = null): SalesVoucher
    {
        return DB::transaction(function () use ($voucher, $remarks) {
            $this->assertBusiness($voucher);

            if (!in_array($voucher->status, ['confirmed', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'Only posted invoices can be reversed.']);
            }

            $this->stock->reverseTransaction(SalesVoucher::class, $voucher->id, $remarks ?: 'Sales reversal');
            $voucher->update(['status' => 'reversed']);

            return $this->fresh($voucher);
        });
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        $this->customers->defaultWalkIn();

        return [
            'customers' => Customer::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('customer_name')->limit(100)->get(),
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'branch_id', 'name', 'code']),
            'payment_methods' => PaymentMethod::query()->where(function (Builder $q) use ($businessId) {
                $q->whereNull('business_id')->orWhere('business_id', $businessId);
            })->where('status', 'active')->orderBy('name')->get(['id', 'name', 'type']),
        ];
    }

    public function searchProducts(string $search, array $scope = [])
    {
        $businessId = AppController::businessId();
        $branchId = $scope['branch_id'] ?? null;
        $warehouseId = $scope['warehouse_id'] ?? null;
        $priceType = $scope['price_type'] ?? 'retail';

        return Product::query()
            ->with(['barcodes', 'variantItems', 'images', 'batches' => fn ($q) => $q->where('status', 'active')->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))->orderBy('expiry_date')])
            ->where(function (Builder $q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->where('status', 'active')
            ->where(function (Builder $q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('primary_barcode', 'like', $like)
                    ->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', 'like', $like));
            })
            ->limit(20)
            ->get()
            ->map(function (Product $product) use ($businessId, $branchId, $warehouseId, $priceType) {
                return $this->presentProduct($product, $businessId, $branchId, $warehouseId, $priceType);
            });
    }

    public function scanProduct(string $barcode, array $scope = []): array
    {
        $barcode = trim($barcode);
        $businessId = AppController::businessId();
        $branchId = $scope['branch_id'] ?? null;
        $warehouseId = $scope['warehouse_id'] ?? null;
        $priceType = $scope['price_type'] ?? 'retail';

        if (!$branchId || !$warehouseId) {
            throw ValidationException::withMessages(['barcode' => 'Please select branch and warehouse before scanning.']);
        }

        Branch::query()->where('business_id', $businessId)->where('status', 'active')->where('id', $branchId)->firstOrFail();
        Warehouse::query()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->where('id', $warehouseId)
            ->where('branch_id', $branchId)
            ->firstOrFail();

        $variantId = null;
        $batchId = null;
        $hasExtraBarcodes = Schema::hasColumn('products', 'extra_barcodes');
        $product = Product::query()
            ->with([
                'barcodes' => fn ($q) => $q->where('business_id', $businessId)->where('is_active', 1)->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active')),
                'variantItems',
                'images',
                'batches' => fn ($q) => $q->where('status', 'active')->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString()))->orderBy('expiry_date'),
            ])
            ->where(function (Builder $q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->where(function (Builder $q) use ($barcode, $hasExtraBarcodes) {
                $q->where('barcode', $barcode)
                    ->orWhere('primary_barcode', $barcode)
                    ->orWhereHas('barcodes', function (Builder $b) use ($barcode) {
                        $b->where('barcode', $barcode)
                            ->where('business_id', AppController::businessId())
                            ->where('is_active', 1)
                            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', 'active'));
                    })
                    ->orWhereHas('variantItems', fn (Builder $v) => $v->where('barcode', $barcode)->whereNull('deleted_at'));
                if ($hasExtraBarcodes) {
                    $q->orWhereRaw('FIND_IN_SET(?, REPLACE(COALESCE(extra_barcodes, ""), " ", ""))', [$barcode]);
                }
            })
            ->first();

        if (!$product) {
            throw ValidationException::withMessages(['barcode' => 'Product not found']);
        }

        $variant = $product->variantItems->firstWhere('barcode', $barcode);
        if ($variant) {
            $variantId = $variant->id;
        }

        $mapped = $product->barcodes->firstWhere('barcode', $barcode);
        if ($mapped) {
            $variantId = $mapped->product_variant_id ?: $variantId;
            $batchId = $mapped->batch_id ?: null;
        }

        $presented = $this->presentProduct($product, $businessId, (int) $branchId, (int) $warehouseId, $priceType, $variantId, $batchId, $barcode);
        if ($this->requiresStock($product) && (float) ($presented['available_stock'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['barcode' => 'Insufficient stock']);
        }

        return $presented;
    }

    public function reports(array $filters = []): array
    {
        $businessId = AppController::businessId();
        $base = SalesVoucher::query()
            ->whereIn('status', ['confirmed', 'approved'])
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('invoice_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('invoice_date', '<=', $filters['date_to']));
        AppController::applyTenantScope($base, 'sales_vouchers');

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        return [
            'daily_sales' => (clone $base)->selectRaw('invoice_date, COUNT(*) as invoices, SUM(grand_total) as total')->groupBy('invoice_date')->latest('invoice_date')->limit(30)->get(),
            'payment_modes' => DB::table('sales_payments')
                ->join('payment_methods', 'payment_methods.id', '=', 'sales_payments.payment_method_id')
                ->join('sales_vouchers', 'sales_vouchers.id', '=', 'sales_payments.sales_voucher_id')
                ->where('sales_payments.business_id', $businessId)
                ->when(AppController::branchId(), fn ($q) => $q->where('sales_vouchers.branch_id', AppController::branchId()))
                ->selectRaw('payment_methods.name, SUM(sales_payments.amount) as amount')
                ->groupBy('payment_methods.name')
                ->get(),
            'tax_summary' => (clone $base)->selectRaw('tax_type, SUM(taxable_amount) as taxable, SUM(cgst_amount) as cgst, SUM(sgst_amount) as sgst, SUM(igst_amount) as igst, SUM(cess_amount) as cess')->groupBy('tax_type')->get(),
            'outstanding' => (clone $base)->where('balance_amount', '>', 0)->sum('balance_amount'),
            'cancelled' => AppController::applyTenantScope(SalesVoucher::query(), 'sales_vouchers')->where('status', 'cancelled')->whereBetween('invoice_date', [$monthStart, $monthEnd])->count(),
            'today_total' => (clone $base)->whereDate('invoice_date', $today)->sum('grand_total'),
            'today_paid' => (clone $base)->whereDate('invoice_date', $today)->sum('paid_amount'),
            'today_balance' => (clone $base)->whereDate('invoice_date', $today)->sum('balance_amount'),
            'draft_count' => AppController::applyTenantScope(SalesVoucher::query(), 'sales_vouchers')->where('status', 'draft')->count(),
            'held_count' => AppController::applyTenantScope(SalesVoucher::query(), 'sales_vouchers')->where('status', 'hold')->count(),
        ];
    }

    public function exportRows(array $filters = []): array
    {
        return $this->list(array_merge($filters, ['per_page' => 1000]))
            ->getCollection()
            ->map(fn (SalesVoucher $voucher) => [
                $voucher->invoice_number,
                optional($voucher->invoice_date)->format('Y-m-d'),
                $voucher->customer_name_snapshot ?: optional($voucher->customer)->customer_name,
                $voucher->customer_gstin_snapshot,
                optional($voucher->branch)->name,
                optional($voucher->warehouse)->name,
                $voucher->invoice_type,
                (float) $voucher->taxable_amount,
                (float) $voucher->cgst_amount,
                (float) $voucher->sgst_amount,
                (float) $voucher->igst_amount,
                (float) $voucher->cess_amount,
                (float) $voucher->grand_total,
                (float) $voucher->paid_amount,
                (float) $voucher->balance_amount,
                $voucher->payment_status,
                $voucher->status,
            ])
            ->all();
    }

    public function present(SalesVoucher $voucher, bool $includeCost = false): array
    {
        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'invoice_number' => $voucher->invoice_number,
            'invoice_date' => optional($voucher->invoice_date)->format('Y-m-d'),
            'due_date' => optional($voucher->due_date)->format('Y-m-d'),
            'customer_id' => $voucher->customer_id,
            'customer' => $voucher->customer_name_snapshot ?: optional($voucher->customer)->customer_name,
            'customer_mobile' => $voucher->customer_mobile_snapshot,
            'branch_id' => $voucher->branch_id,
            'branch' => optional($voucher->branch)->name,
            'warehouse_id' => $voucher->warehouse_id,
            'warehouse' => optional($voucher->warehouse)->name,
            'sale_type' => $voucher->sale_type,
            'invoice_type' => $voucher->invoice_type,
            'tax_type' => $voucher->tax_type,
            'place_of_supply_state_id' => $voucher->place_of_supply_state_id,
            'subtotal' => (float) $voucher->subtotal,
            'item_discount_amount' => (float) $voucher->item_discount_amount,
            'voucher_discount_type' => $voucher->voucher_discount_type,
            'voucher_discount_value' => (float) $voucher->voucher_discount_value,
            'voucher_discount_amount' => (float) $voucher->voucher_discount_amount,
            'taxable_amount' => (float) $voucher->taxable_amount,
            'cgst_amount' => (float) $voucher->cgst_amount,
            'sgst_amount' => (float) $voucher->sgst_amount,
            'igst_amount' => (float) $voucher->igst_amount,
            'cess_amount' => (float) $voucher->cess_amount,
            'shipping_amount' => (float) $voucher->shipping_amount,
            'other_charges' => (float) $voucher->other_charges,
            'round_off' => (float) $voucher->round_off,
            'grand_total' => (float) $voucher->grand_total,
            'paid_amount' => (float) $voucher->paid_amount,
            'balance_amount' => (float) $voucher->balance_amount,
            'change_returned' => (float) $voucher->change_returned,
            'payment_status' => $voucher->payment_status,
            'status' => $voucher->status,
            'salesperson' => optional($voucher->salesperson)->name,
            'created_by' => optional($voucher->creator)->name,
            'reference_number' => $voucher->reference_number,
            'remarks' => $voucher->remarks,
            'terms_and_conditions' => $voucher->terms_and_conditions,
            'items' => $voucher->items->map(function ($item) use ($includeCost) {
                $row = [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product' => $item->product_name_snapshot,
                    'product_variant_id' => $item->product_variant_id,
                    'batch_id' => $item->batch_id,
                    'unit_id' => $item->unit_id,
                    'barcode_snapshot' => $item->barcode_snapshot,
                    'sku_snapshot' => $item->sku_snapshot,
                    'hsn_code_snapshot' => $item->hsn_code_snapshot,
                    'quantity' => (float) $item->quantity,
                    'free_quantity' => (float) $item->free_quantity,
                    'selling_rate' => (float) $item->selling_rate,
                    'mrp' => $item->mrp !== null ? (float) $item->mrp : null,
                    'discount_type' => $item->discount_type,
                    'discount_value' => (float) $item->discount_value,
                    'discount_amount' => (float) $item->discount_amount,
                    'taxable_amount' => (float) $item->taxable_amount,
                    'gst_rate' => (float) $item->gst_rate,
                    'cess_rate' => (float) $item->cess_rate,
                    'line_total' => (float) $item->line_total,
                    'remarks' => $item->remarks,
                ];
                if ($includeCost) {
                    $row['cost_rate'] = $item->cost_rate !== null ? (float) $item->cost_rate : null;
                }

                return $row;
            })->values(),
            'payments' => $voucher->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'payment_method_id' => $payment->payment_method_id,
                'payment_method' => optional($payment->method)->name,
                'amount' => (float) $payment->amount,
                'reference_number' => $payment->reference_number,
                'payment_date' => optional($payment->payment_date)->format('Y-m-d'),
                'notes' => $payment->notes,
            ])->values(),
        ];
    }

    private function prepareCustomer(array &$data): void
    {
        if (empty($data['customer_id']) && ($data['sale_type'] ?? 'cash') === 'cash') {
            $data['customer_id'] = $this->customers->defaultWalkIn()->id;
        }
    }

    private function voucherAttributes(int $businessId, array $data, array $totals, array $extra = []): array
    {
        $customer = Customer::query()->where('business_id', $businessId)->where('id', $data['customer_id'])->first();

        return array_merge([
            'business_id' => $businessId,
            'branch_id' => $data['branch_id'],
            'warehouse_id' => $data['warehouse_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'] ?? $this->dueDate($customer, $data['invoice_date']),
            'sale_type' => $data['sale_type'],
            'invoice_type' => $data['invoice_type'],
            'tax_type' => $data['invoice_type'] === 'bill_of_supply' ? 'exempt' : $data['tax_type'],
            'place_of_supply_state_id' => $data['place_of_supply_state_id'] ?? null,
            'customer_name_snapshot' => optional($customer)->customer_name,
            'customer_mobile_snapshot' => optional($customer)->mobile ?: optional($customer)->phone,
            'customer_gstin_snapshot' => optional($customer)->gstin,
            'billing_address_snapshot' => optional($customer)->billing_address,
            'shipping_address_snapshot' => optional($customer)->shipping_address,
            'subtotal' => $totals['subtotal'],
            'item_discount_amount' => $totals['item_discount_amount'],
            'voucher_discount_type' => $data['voucher_discount_type'] ?? null,
            'voucher_discount_value' => $data['voucher_discount_value'] ?? 0,
            'voucher_discount_amount' => $totals['voucher_discount_amount'],
            'taxable_amount' => $totals['taxable_amount'],
            'cgst_amount' => $totals['cgst_amount'],
            'sgst_amount' => $totals['sgst_amount'],
            'igst_amount' => $totals['igst_amount'],
            'cess_amount' => $totals['cess_amount'],
            'shipping_amount' => $data['shipping_amount'] ?? 0,
            'other_charges' => $data['other_charges'] ?? 0,
            'round_off' => $totals['round_off'],
            'grand_total' => $totals['grand_total'],
            'paid_amount' => $totals['paid_amount'],
            'balance_amount' => $totals['balance_amount'],
            'change_returned' => $totals['change_returned'],
            'payment_status' => $totals['payment_status'],
            'reference_number' => $data['reference_number'] ?? null,
            'salesperson_id' => $data['salesperson_id'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'terms_and_conditions' => $data['terms_and_conditions'] ?? null,
        ], $extra);
    }

    private function syncItems(SalesVoucher $voucher, array $items): void
    {
        foreach ($items as $item) {
            $product = Product::query()->with('hsn')->findOrFail($item['product_id']);
            $scope = [
                'business_id' => $voucher->business_id,
                'branch_id' => $voucher->branch_id,
                'warehouse_id' => $voucher->warehouse_id,
                'product_id' => $product->id,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'batch_id' => $item['batch_id'] ?? null,
            ];

            $voucher->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $item['product_variant_id'] ?? null,
                'batch_id' => $item['batch_id'] ?? null,
                'unit_id' => $item['unit_id'] ?? $product->unit_id,
                'barcode_snapshot' => $product->primary_barcode ?: $product->barcode,
                'product_name_snapshot' => $product->invoice_description ?: $product->name,
                'sku_snapshot' => $product->sku,
                'hsn_code_snapshot' => $product->hsn_code ?: $product->hsn ?: optional($product->hsn)->hsn_code,
                'quantity' => $item['quantity'],
                'free_quantity' => $item['free_quantity'] ?? 0,
                'selling_rate' => $item['selling_rate'],
                'mrp' => $item['mrp'] ?? $product->mrp,
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
                'cost_rate' => $this->stock->getAverageCost($scope),
                'salesperson_id' => $item['salesperson_id'] ?? $voucher->salesperson_id,
                'remarks' => $item['remarks'] ?? null,
            ]);
        }
    }

    private function syncPayments(SalesVoucher $voucher, array $payments): void
    {
        foreach ($payments as $payment) {
            $voucher->payments()->create([
                'business_id' => $voucher->business_id,
                'payment_method_id' => $payment['payment_method_id'],
                'amount' => $payment['amount'],
                'reference_number' => $payment['reference_number'] ?? null,
                'payment_date' => $payment['payment_date'] ?? $voucher->invoice_date,
                'notes' => $payment['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);
        }
    }

    private function validateOwnership(int $businessId, array $data, ?int $currentVoucherId = null): void
    {
        Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        Warehouse::query()->where('business_id', $businessId)->where('id', $data['warehouse_id'])->firstOrFail();

        $customer = null;
        if (!empty($data['customer_id'])) {
            $customer = Customer::query()->where('business_id', $businessId)->where('status', 'active')->where('id', $data['customer_id'])->firstOrFail();
        }

        if (($data['sale_type'] ?? 'cash') === 'credit' && $customer && $customer->customer_type === 'walk_in') {
            throw ValidationException::withMessages(['customer_id' => 'Walk-in customer cannot be used for credit sale.']);
        }

        foreach ($data['items'] as $index => $item) {
            $product = Product::query()
                ->with(['variantItems', 'batches'])
                ->where('status', 'active')
                ->where('id', $item['product_id'])
                ->where(function (Builder $q) use ($businessId) {
                    $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
                })
                ->firstOrFail();

            $mrp = (float) ($item['mrp'] ?? $product->mrp ?? 0);
            if ($mrp > 0 && (float) $item['selling_rate'] > $mrp) {
                throw ValidationException::withMessages(["items.$index.selling_rate" => 'Selling price cannot exceed MRP.']);
            }

            if ($product->variantItems->count() > 0 && empty($item['product_variant_id'])) {
                throw ValidationException::withMessages(["items.$index.product_variant_id" => 'Variant is required for this product.']);
            }

            if (!empty($item['product_variant_id'])) {
                ProductVariantItem::query()->where('business_id', $businessId)->where('product_id', $product->id)->where('id', $item['product_variant_id'])->firstOrFail();
            }

            $batchRequired = $product->batch_required || in_array($product->tracking_type, ['batch', 'batch_expiry'], true);
            if ($batchRequired && empty($item['batch_id'])) {
                throw ValidationException::withMessages(["items.$index.batch_id" => 'Batch is required for this product.']);
            }

            if (!empty($item['batch_id'])) {
                $batch = ProductBatch::query()->where('business_id', $businessId)->where('product_id', $product->id)->where('id', $item['batch_id'])->firstOrFail();
                if (in_array($batch->status, ['blocked', 'quarantined'], true)) {
                    throw ValidationException::withMessages(["items.$index.batch_id" => 'Blocked or quarantined batch cannot be sold.']);
                }
                if ($batch->expiry_date && $batch->expiry_date->isPast()) {
                    throw ValidationException::withMessages(["items.$index.batch_id" => 'Expired batch cannot be sold.']);
                }
            }

            if ($product->product_type !== 'service' && $product->item_type !== 'non_stock' && (bool) ($product->track_inventory ?? true) && in_array($data['status'], ['confirmed', 'approved'], true)) {
                $this->stock->validateAvailableStock([
                    'business_id' => $businessId,
                    'branch_id' => $data['branch_id'],
                    'warehouse_id' => $data['warehouse_id'],
                    'product_id' => $product->id,
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'batch_id' => $item['batch_id'] ?? null,
                ], (float) $item['quantity'] + (float) ($item['free_quantity'] ?? 0));
            }
        }

        foreach ($data['payments'] ?? [] as $index => $payment) {
            PaymentMethod::query()
                ->where('id', $payment['payment_method_id'])
                ->where(function (Builder $q) use ($businessId) {
                    $q->whereNull('business_id')->orWhere('business_id', $businessId);
                })
                ->firstOrFail();
        }
    }

    private function validateCreditLimit(int $businessId, array $data, array $totals, ?int $currentVoucherId = null): void
    {
        if (($data['sale_type'] ?? 'cash') !== 'credit' || empty($data['customer_id'])) {
            return;
        }

        $customer = Customer::query()->where('business_id', $businessId)->where('id', $data['customer_id'])->firstOrFail();
        if ($customer->credit_limit === null) {
            return;
        }

        $outstanding = (float) SalesVoucher::query()
            ->where('business_id', $businessId)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['confirmed', 'approved'])
            ->when($currentVoucherId, fn (Builder $q) => $q->where('id', '!=', $currentVoucherId))
            ->sum('balance_amount');

        if ($outstanding + (float) $totals['balance_amount'] > (float) $customer->credit_limit) {
            throw ValidationException::withMessages(['customer_id' => 'Customer credit limit exceeded.']);
        }
    }

    private function presentProduct(Product $product, int $businessId, ?int $branchId, ?int $warehouseId, string $priceType, ?int $variantId = null, ?int $batchId = null, ?string $scannedBarcode = null): array
    {
        $priceField = [
            'wholesale' => 'wholesale_price',
            'dealer' => 'dealer_price',
            'online' => 'online_price',
        ][$priceType] ?? 'selling_price';
        $stockScope = ['business_id' => $businessId, 'product_id' => $product->id];
        if ($variantId) {
            $stockScope['product_variant_id'] = $variantId;
        }
        if ($branchId) {
            $stockScope['branch_id'] = $branchId;
        }
        if ($warehouseId) {
            $stockScope['warehouse_id'] = $warehouseId;
        }

        $batches = $product->batches->map(function ($batch) use ($stockScope) {
            $scope = array_merge($stockScope, ['batch_id' => $batch->id]);
            return [
                'id' => $batch->id,
                'batch_no' => $batch->batch_no ?: $batch->batch_number,
                'expiry_date' => optional($batch->expiry_date)->format('Y-m-d'),
                'available_stock' => $this->stock->getCurrentStock($scope),
            ];
        })->filter(fn ($batch) => (float) $batch['available_stock'] > 0 || (int) $batch['id'] === (int) $batchId)->values();
        $selectedBatchId = $batchId ?: ($batches->first()['id'] ?? null);
        $availableStock = $product->product_type === 'service' ? null : $this->stock->getCurrentStock($selectedBatchId ? array_merge($stockScope, ['batch_id' => $selectedBatchId]) : $stockScope);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $scannedBarcode ?: ($product->primary_barcode ?: $product->barcode),
            'image_url' => $this->imageUrl(optional($product->images->sortByDesc('is_primary')->first())->image_path),
            'unit_id' => $product->unit_id,
            'selling_rate' => (float) ($product->{$priceField} ?: $product->selling_price ?: $product->sale_price ?: $product->default_selling_price),
            'mrp' => $product->mrp !== null ? (float) $product->mrp : null,
            'gst_rate' => (float) $product->gst_rate,
            'cess_rate' => (float) $product->cess_rate,
            'tax_inclusive' => (bool) $product->tax_inclusive,
            'product_type' => $product->product_type,
            'item_type' => $product->item_type,
            'tracking_type' => $product->tracking_type ?: 'none',
            'batch_required' => (bool) $product->batch_required,
            'serial_required' => (bool) ($product->serial_required || $product->tracking_type === 'serial'),
            'product_variant_id' => $variantId,
            'batch_id' => $selectedBatchId,
            'available_stock' => $availableStock,
            'variants' => $product->variantItems->map(fn ($variant) => ['id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode])->values(),
            'batches' => $batches,
        ];
    }

    private function requiresStock(Product $product): bool
    {
        return $product->product_type !== 'service' && $product->item_type !== 'non_stock' && (bool) ($product->track_inventory ?? true);
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return asset($path);
    }

    private function dueDate(?Customer $customer, string $invoiceDate): ?string
    {
        if (!$customer || !$customer->credit_days) {
            return null;
        }

        return date('Y-m-d', strtotime($invoiceDate . ' +' . (int) $customer->credit_days . ' days'));
    }

    private function hasStockPosting(SalesVoucher $voucher): bool
    {
        return DB::table('stock_ledgers')
            ->where('business_id', $voucher->business_id)
            ->where('reference_type', SalesVoucher::class)
            ->where('reference_id', $voucher->id)
            ->where('transaction_type', 'sale')
            ->exists();
    }

    private function assertBusiness(SalesVoucher $voucher): void
    {
        abort_unless((int) $voucher->business_id === AppController::businessId(), 404);
    }

    private function fresh(SalesVoucher $voucher): SalesVoucher
    {
        return $voucher->fresh(['customer', 'branch', 'warehouse', 'salesperson', 'creator', 'items.product', 'items.variant', 'items.batch', 'payments.method']);
    }
}
