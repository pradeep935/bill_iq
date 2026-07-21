<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\Branch;
use App\Models\BusinessInventorySetting;
use App\Models\InventoryStockStatus;
use App\Models\JournalVoucher;
use App\Models\LocationTransferVoucher;
use App\Models\Product;
use App\Models\StockAdjustmentReason;
use App\Models\StockAdjustmentVoucher;
use App\Models\StockCountSession;
use App\Models\StockLedger;
use App\Models\StockReservation;
use App\Models\StockTransferVoucher;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryControlService
{
    private StockService $stock;
    private AccountingPostingService $accounting;

    public function __construct(StockService $stock, AccountingPostingService $accounting)
    {
        $this->stock = $stock;
        $this->accounting = $accounting;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        return [
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'branch_id', 'name', 'code']),
            'reasons' => StockAdjustmentReason::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('reason_name')->get(),
            'statuses' => InventoryStockStatus::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(),
            'products' => Product::query()->where(function (Builder $q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })->where('status', 'active')->orderBy('name')->limit(200)->get(['id', 'name', 'sku', 'primary_barcode', 'barcode', 'unit_id', 'batch_tracking', 'serial_tracking']),
            'settings' => BusinessInventorySetting::query()->where('business_id', $businessId)->first(),
        ];
    }

    public function searchProducts(string $q)
    {
        $businessId = AppController::businessId();
        return Product::query()->where(function (Builder $query) use ($businessId) {
            $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
        })->where(function (Builder $query) use ($q) {
            $query->where('name', 'like', '%' . $q . '%')->orWhere('sku', 'like', '%' . $q . '%')->orWhere('primary_barcode', $q)->orWhere('barcode', $q)
                ->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', $q));
        })->where('status', 'active')->limit(20)->get(['id', 'name', 'sku', 'primary_barcode', 'barcode', 'unit_id', 'batch_tracking', 'serial_tracking']);
    }

    public function reasons(array $filters)
    {
        return StockAdjustmentReason::query()->with('account')->where('business_id', AppController::businessId())
            ->when(!empty($filters['search']), fn (Builder $q) => $q->where('reason_name', 'like', '%' . $filters['search'] . '%')->orWhere('reason_code', 'like', '%' . $filters['search'] . '%'))
            ->latest('id')->paginate(50);
    }

    public function saveReason(array $data, ?int $id = null): StockAdjustmentReason
    {
        $businessId = AppController::businessId();
        if (!empty($data['accounting_account_id'])) $this->assertAccount($data['accounting_account_id']);
        $reason = $id ? StockAdjustmentReason::query()->where('business_id', $businessId)->findOrFail($id) : new StockAdjustmentReason(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $reason->fill(array_merge($data, ['updated_by' => Auth::id()]))->save();
        return $reason->fresh('account');
    }

    public function deleteReason(int $id, bool $force = false): void
    {
        $reason = StockAdjustmentReason::query()->withTrashed()->where('business_id', AppController::businessId())->findOrFail($id);
        if ($reason->is_system) throw ValidationException::withMessages(['reason' => 'System reasons cannot be deleted.']);
        if ($force && $reason->vouchers()->exists()) throw ValidationException::withMessages(['reason' => 'Reasons linked to vouchers cannot be permanently deleted.']);
        $force ? $reason->forceDelete() : $reason->delete();
    }

    public function adjustments(array $filters)
    {
        return StockAdjustmentVoucher::query()->with(['branch', 'warehouse', 'reason'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['source']), fn (Builder $q) => $q->where('source', $filters['source']))
            ->latest('id')->paginate(20);
    }

    public function saveAdjustment(array $data, ?int $id = null): StockAdjustmentVoucher
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->assertWarehouse($data['warehouse_id'], $data['branch_id'] ?? null);
            if (!empty($data['adjustment_reason_id'])) $this->assertReason($data['adjustment_reason_id']);
            $items = $this->prepareAdjustmentItems($data);
            unset($data['items']);
            $voucher = $id ? StockAdjustmentVoucher::query()->where('business_id', $businessId)->with('items')->findOrFail($id) : new StockAdjustmentVoucher(['business_id' => $businessId, 'voucher_number' => $this->nextNumber('ADJ', StockAdjustmentVoucher::class, 'voucher_number'), 'created_by' => Auth::id()]);
            if (in_array($voucher->status, ['posted', 'reversed', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Posted stock adjustments cannot be edited.']);
            $totals = $this->adjustmentTotals($items);
            $voucher->fill(array_merge($data, $totals))->save();
            $voucher->items()->delete();
            $voucher->items()->createMany($items);
            if (in_array($data['status'], ['approved', 'posted'], true)) $this->postAdjustment($voucher->id);
            return $voucher->fresh(['items.product', 'warehouse', 'reason']);
        });
    }

    public function postAdjustment(int $id): StockAdjustmentVoucher
    {
        return DB::transaction(function () use ($id) {
            $voucher = StockAdjustmentVoucher::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($id);
            if ($this->stockAlreadyPosted(StockAdjustmentVoucher::class, $voucher->id)) return $voucher;
            foreach ($voucher->items as $item) {
                $payload = $this->stockPayload($voucher, $item, [
                    'transaction_type' => $item->direction === 'in' ? 'stock_adjustment_in' : $this->outTypeForCondition($item->condition_status),
                    'reference_type' => StockAdjustmentVoucher::class,
                    'reference_id' => $voucher->id,
                    'quantity' => (float) $item->adjustment_quantity,
                    'unit_cost' => (float) $item->unit_cost,
                    'warehouse_location' => $item->warehouse_location,
                    'stock_status' => $item->condition_status ?: 'saleable',
                    'remarks' => $item->reason ?: $voucher->remarks,
                ]);
                $item->direction === 'in' ? $this->stock->increaseStock($payload) : $this->stock->decreaseStock($payload);
            }
            $journal = $this->postAdjustmentAccounting($voucher);
            $voucher->update(['status' => 'posted', 'journal_voucher_id' => $journal ? $journal->id : null, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            return $voucher->fresh(['items.product', 'journal']);
        });
    }

    public function reverseAdjustment(int $id, string $remarks): StockAdjustmentVoucher
    {
        return DB::transaction(function () use ($id, $remarks) {
            $voucher = StockAdjustmentVoucher::query()->where('business_id', AppController::businessId())->with('journal.entries')->findOrFail($id);
            if ($voucher->status !== 'posted') throw ValidationException::withMessages(['status' => 'Only posted adjustments can be reversed.']);
            $this->stock->reverseTransaction(StockAdjustmentVoucher::class, $voucher->id, $remarks);
            if ($voucher->journal) $this->accounting->reverseJournalVoucher($voucher->journal, $remarks);
            $voucher->update(['status' => 'reversed', 'cancelled_by' => Auth::id(), 'cancelled_at' => now()]);
            return $voucher->fresh('journal');
        });
    }

    public function countSessions(array $filters)
    {
        return StockCountSession::query()->with(['branch', 'warehouse'])->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function saveCountSession(array $data, ?int $id = null): StockCountSession
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->assertWarehouse($data['warehouse_id'], $data['branch_id'] ?? null);
            $items = $this->prepareCountItems($data);
            unset($data['items']);
            $session = $id ? StockCountSession::query()->where('business_id', $businessId)->with('items')->findOrFail($id) : new StockCountSession(['business_id' => $businessId, 'session_number' => $this->nextNumber('CNT', StockCountSession::class, 'session_number'), 'created_by' => Auth::id()]);
            if (in_array($session->status, ['posted', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Posted count sessions cannot be edited.']);
            $session->fill($data)->save();
            if ($items) {
                $session->items()->delete();
                $session->items()->createMany($items);
            }
            return $session->fresh(['items.product', 'warehouse']);
        });
    }

    public function scanCountLine(int $sessionId, array $data): StockCountSession
    {
        return DB::transaction(function () use ($sessionId, $data) {
            $session = StockCountSession::query()->where('business_id', AppController::businessId())->findOrFail($sessionId);
            $product = $this->assertProduct($data['product_id']);
            $item = $session->items()->firstOrNew(['product_id' => $product->id, 'batch_id' => $data['batch_id'] ?? null, 'warehouse_location' => $data['warehouse_location'] ?? null]);
            if (!$item->exists) {
                $item->system_quantity = $this->stock->getCurrentStock($this->scope($session->branch_id, $session->warehouse_id, $product->id, null, $data['batch_id'] ?? null));
                $item->unit_cost = $this->stock->getAverageCost($this->scope($session->branch_id, $session->warehouse_id, $product->id, null, $data['batch_id'] ?? null));
            }
            $item->counted_quantity = (float) ($item->counted_quantity ?? 0) + (float) ($data['quantity'] ?? 1);
            $item->variance_quantity = round((float) $item->counted_quantity - (float) $item->system_quantity, 3);
            $item->variance_value = round(abs((float) $item->variance_quantity) * (float) $item->unit_cost, 2);
            $item->review_status = 'pending';
            $item->counted_by = Auth::id();
            $item->counted_at = now();
            $item->save();
            $session->update(['status' => 'counting']);
            return $session->fresh(['items.product']);
        });
    }

    public function postCountVariance(int $sessionId): StockAdjustmentVoucher
    {
        return DB::transaction(function () use ($sessionId) {
            $session = StockCountSession::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($sessionId);
            $items = $session->items->filter(fn ($i) => $i->review_status === 'accepted' && round((float) $i->variance_quantity, 3) != 0.0)->map(fn ($i) => [
                'product_id' => $i->product_id, 'product_variant_id' => $i->product_variant_id, 'batch_id' => $i->batch_id,
                'unit_id' => null, 'adjustment_quantity' => abs((float) $i->variance_quantity), 'direction' => (float) $i->variance_quantity > 0 ? 'in' : 'out',
                'unit_cost' => (float) $i->unit_cost, 'warehouse_location' => $i->warehouse_location, 'condition_status' => 'saleable',
                'reason' => 'Physical count variance', 'actual_quantity' => $i->counted_quantity,
            ])->values()->all();
            if (!$items) throw ValidationException::withMessages(['items' => 'No accepted variances to post.']);
            $voucher = $this->saveAdjustment([
                'branch_id' => $session->branch_id, 'warehouse_id' => $session->warehouse_id, 'adjustment_date' => now()->toDateString(),
                'adjustment_reason_id' => null, 'adjustment_type' => 'mixed', 'source' => 'physical_count', 'status' => 'posted',
                'remarks' => 'Variance posting for ' . $session->session_number, 'items' => $items,
            ]);
            $session->update(['status' => 'posted', 'approved_by' => Auth::id(), 'approved_at' => now(), 'completed_at' => now()]);
            return $voucher;
        });
    }

    public function transfers(array $filters)
    {
        return StockTransferVoucher::query()->with(['sourceWarehouse', 'destinationWarehouse', 'items.product'])->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function saveTransfer(array $data, ?int $id = null): StockTransferVoucher
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            if ((int) $data['source_warehouse_id'] === (int) $data['destination_warehouse_id']) throw ValidationException::withMessages(['destination_warehouse_id' => 'Source and destination warehouse cannot be same.']);
            $this->assertWarehouse($data['source_warehouse_id'], $data['source_branch_id'] ?? null);
            $this->assertWarehouse($data['destination_warehouse_id'], $data['destination_branch_id'] ?? null);
            $items = $this->prepareTransferItems($data);
            unset($data['items']);
            $voucher = $id ? StockTransferVoucher::query()->where('business_id', $businessId)->with('items')->findOrFail($id) : new StockTransferVoucher(['business_id' => $businessId, 'voucher_number' => $this->nextNumber('TRF', StockTransferVoucher::class, 'voucher_number'), 'created_by' => Auth::id()]);
            if (in_array($voucher->status, ['received', 'reversed', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Finalized transfers cannot be edited.']);
            $voucher->fill($data)->save();
            $voucher->items()->delete();
            $voucher->items()->createMany($items);
            if ($data['status'] === 'approved' && $data['transfer_type'] === 'immediate') $this->postImmediateTransfer($voucher->id);
            if ($data['status'] === 'dispatched') $this->dispatchTransfer($voucher->id);
            if ($data['status'] === 'received') $this->receiveTransfer($voucher->id, ['items' => $voucher->items()->get(['id', 'approved_quantity'])->map(fn ($i) => ['id' => $i->id, 'received_quantity' => (float) ($i->approved_quantity ?: $i->requested_quantity), 'rejected_quantity' => 0])->all()]);
            return $voucher->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    public function postImmediateTransfer(int $id): StockTransferVoucher
    {
        return DB::transaction(function () use ($id) {
            $voucher = StockTransferVoucher::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($id);
            if ($this->stockAlreadyPosted(StockTransferVoucher::class, $voucher->id)) return $voucher;
            foreach ($voucher->items as $item) {
                $qty = (float) ($item->approved_quantity ?: $item->requested_quantity);
                $this->stock->decreaseStock($this->transferPayload($voucher, $item, $qty, $voucher->source_branch_id, $voucher->source_warehouse_id, 'stock_transfer_out', $item->source_location));
                $this->stock->increaseStock($this->transferPayload($voucher, $item, $qty, $voucher->destination_branch_id, $voucher->destination_warehouse_id, 'stock_transfer_in', $item->destination_location));
                $item->update(['dispatched_quantity' => $qty, 'received_quantity' => $qty]);
            }
            $voucher->update(['status' => 'received', 'approved_by' => Auth::id(), 'approved_at' => now(), 'dispatched_by' => Auth::id(), 'dispatched_at' => now(), 'received_by' => Auth::id(), 'received_at' => now()]);
            return $voucher->fresh('items');
        });
    }

    public function dispatchTransfer(int $id): StockTransferVoucher
    {
        return DB::transaction(function () use ($id) {
            $voucher = StockTransferVoucher::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($id);
            if (StockLedger::query()->where('business_id', $voucher->business_id)->where('reference_type', StockTransferVoucher::class)->where('reference_id', $voucher->id)->where('transaction_type', 'stock_transfer_out')->exists()) return $voucher;
            foreach ($voucher->items as $item) {
                $qty = (float) ($item->approved_quantity ?: $item->requested_quantity);
                $this->stock->decreaseStock($this->transferPayload($voucher, $item, $qty, $voucher->source_branch_id, $voucher->source_warehouse_id, 'stock_transfer_out', $item->source_location));
                $item->update(['dispatched_quantity' => $qty]);
            }
            $voucher->update(['status' => 'dispatched', 'dispatched_by' => Auth::id(), 'dispatched_at' => now()]);
            return $voucher->fresh('items');
        });
    }

    public function receiveTransfer(int $id, array $data): StockTransferVoucher
    {
        return DB::transaction(function () use ($id, $data) {
            $voucher = StockTransferVoucher::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($id);
            foreach ($data['items'] as $row) {
                $item = $voucher->items->firstWhere('id', (int) $row['id']);
                if (!$item) continue;
                $already = (float) $item->received_quantity;
                $qty = (float) $row['received_quantity'];
                $rejected = (float) ($row['rejected_quantity'] ?? 0);
                if ($qty + $already > (float) $item->dispatched_quantity) throw ValidationException::withMessages(['received_quantity' => 'Received quantity cannot exceed dispatched quantity.']);
                if ($qty > 0) $this->stock->increaseStock($this->transferPayload($voucher, $item, $qty, $voucher->destination_branch_id, $voucher->destination_warehouse_id, 'stock_transfer_in', $row['destination_location'] ?? $item->destination_location));
                $item->update(['received_quantity' => $already + $qty, 'rejected_quantity' => (float) $item->rejected_quantity + $rejected]);
            }
            $allReceived = $voucher->items()->get()->every(fn ($i) => (float) $i->received_quantity >= (float) $i->dispatched_quantity);
            $voucher->update(['status' => $allReceived ? 'received' : 'partially_received', 'received_by' => Auth::id(), 'received_at' => now()]);
            return $voucher->fresh('items');
        });
    }

    public function locationTransfers(array $filters)
    {
        return LocationTransferVoucher::query()->with(['warehouse'])->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function saveLocationTransfer(array $data, ?int $id = null): LocationTransferVoucher
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->assertWarehouse($data['warehouse_id'], $data['branch_id'] ?? null);
            foreach ($data['items'] as $row) {
                if ($row['from_location'] === $row['to_location']) throw ValidationException::withMessages(['to_location' => 'From and To locations cannot be same.']);
                $this->assertProduct($row['product_id']);
                $this->stock->validateAvailableStock($this->scope($data['branch_id'] ?? null, $data['warehouse_id'], $row['product_id'], $row['product_variant_id'] ?? null, $row['batch_id'] ?? null), (float) $row['quantity']);
            }
            $items = $data['items'];
            unset($data['items']);
            $voucher = $id ? LocationTransferVoucher::query()->where('business_id', $businessId)->findOrFail($id) : new LocationTransferVoucher(['business_id' => $businessId, 'voucher_number' => $this->nextNumber('LOC', LocationTransferVoucher::class, 'voucher_number'), 'created_by' => Auth::id()]);
            $voucher->fill($data)->save();
            $voucher->items()->delete();
            $voucher->items()->createMany($items);
            if (in_array($data['status'], ['approved', 'posted'], true)) $voucher->update(['status' => 'posted', 'approved_by' => Auth::id()]);
            return $voucher->fresh(['items.product', 'warehouse']);
        });
    }

    public function dashboard(array $filters): array
    {
        $businessId = AppController::businessId();
        $summary = $this->stock->summary(array_merge($filters, ['per_page' => 100]))->getCollection();
        return [
            'total_stock_value' => round($summary->sum(fn ($r) => (float) $r->stock_value), 2),
            'total_saleable_quantity' => round($summary->sum(fn ($r) => (float) $r->quantity_available), 3),
            'low_stock_items' => $summary->where('stock_status', 'Low Stock')->count(),
            'out_of_stock_items' => $summary->where('stock_status', 'Out of Stock')->count(),
            'near_expiry_items' => StockLedger::query()->where('business_id', $businessId)->join('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')->whereBetween('product_batches.expiry_date', [now(), now()->addDays(30)])->distinct('stock_ledgers.batch_id')->count('stock_ledgers.batch_id'),
            'expired_items' => StockLedger::query()->where('business_id', $businessId)->join('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')->whereDate('product_batches.expiry_date', '<', now())->distinct('stock_ledgers.batch_id')->count('stock_ledgers.batch_id'),
            'damaged_stock_value' => (float) StockLedger::query()->where('business_id', $businessId)->where('stock_status', 'damaged')->sum('stock_value'),
            'stock_in_transit' => (float) StockLedger::query()->where('business_id', $businessId)->where('stock_status', 'in_transit')->selectRaw('COALESCE(SUM(quantity_in - quantity_out), 0) as qty')->value('qty'),
            'pending_stock_counts' => StockCountSession::query()->where('business_id', $businessId)->whereIn('status', ['draft', 'assigned', 'counting', 'submitted', 'reviewed'])->count(),
            'pending_transfer_receipts' => StockTransferVoucher::query()->where('business_id', $businessId)->whereIn('status', ['dispatched', 'partially_received'])->count(),
            'stock_adjustment_value' => (float) StockAdjustmentVoucher::query()->where('business_id', $businessId)->where('status', 'posted')->sum(DB::raw('total_value_in + total_value_out')),
        ];
    }

    public function reports(array $filters): array
    {
        $businessId = AppController::businessId();
        return [
            'stock_summary' => $this->stock->summary($filters),
            'ledger' => StockLedger::query()->with(['product', 'warehouse', 'branch', 'creator'])->where('business_id', $businessId)->latest('transaction_date')->limit(200)->get(),
            'transfer_report' => StockTransferVoucher::query()->with(['sourceWarehouse', 'destinationWarehouse'])->where('business_id', $businessId)->latest('id')->limit(100)->get(),
            'variance_report' => StockCountSession::query()->with('items.product')->where('business_id', $businessId)->latest('id')->limit(50)->get(),
            'batch_report' => DB::table('stock_ledgers')->join('products', 'products.id', '=', 'stock_ledgers.product_id')->leftJoin('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')->where('stock_ledgers.business_id', $businessId)->selectRaw('products.name as product_name, product_batches.batch_number, product_batches.batch_no, product_batches.expiry_date, SUM(quantity_in) as quantity_in, SUM(quantity_out) as quantity_out, SUM(quantity_in - quantity_out) as available_quantity, SUM(stock_value) as stock_value')->groupBy('products.name', 'product_batches.batch_number', 'product_batches.batch_no', 'product_batches.expiry_date')->limit(100)->get(),
        ];
    }

    public function valuation(array $scope): array
    {
        return [
            'quantity' => $this->stock->getCurrentStock($scope),
            'reserved' => $this->reservedStock($scope),
            'available' => $this->availableStock($scope),
            'average_cost' => $this->stock->getAverageCost($scope),
            'stock_value' => $this->stock->getStockValue($scope),
            'fifo_note' => 'FIFO layers are reserved for future implementation; weighted average is active.',
        ];
    }

    private function prepareAdjustmentItems(array $data): array
    {
        return collect($data['items'])->map(function ($row) use ($data) {
            $product = $this->assertProduct($row['product_id']);
            $scope = $this->scope($data['branch_id'] ?? null, $data['warehouse_id'], $product->id, $row['product_variant_id'] ?? null, $row['batch_id'] ?? null);
            $systemQty = $this->stock->getCurrentStock($scope);
            if ($row['direction'] === 'out') $this->stock->validateAvailableStock($scope, (float) $row['adjustment_quantity']);
            return array_merge($row, [
                'unit_id' => $row['unit_id'] ?? $product->unit_id ?? null,
                'system_quantity' => $systemQty,
                'adjustment_value' => round((float) $row['adjustment_quantity'] * (float) $row['unit_cost'], 2),
                'condition_status' => $row['condition_status'] ?? 'saleable',
            ]);
        })->all();
    }

    private function prepareCountItems(array $data): array
    {
        if (empty($data['items'])) return [];
        return collect($data['items'])->map(function ($row) use ($data) {
            $product = $this->assertProduct($row['product_id']);
            $scope = $this->scope($data['branch_id'] ?? null, $data['warehouse_id'], $product->id, $row['product_variant_id'] ?? null, $row['batch_id'] ?? null);
            $system = $this->stock->getCurrentStock($scope);
            $counted = array_key_exists('counted_quantity', $row) ? (float) $row['counted_quantity'] : null;
            $variance = $counted === null ? 0 : round($counted - $system, 3);
            $cost = (float) ($row['unit_cost'] ?? $this->stock->getAverageCost($scope));
            return array_merge($row, ['system_quantity' => $system, 'variance_quantity' => $variance, 'unit_cost' => $cost, 'variance_value' => round(abs($variance) * $cost, 2), 'review_status' => $row['review_status'] ?? 'pending']);
        })->all();
    }

    private function prepareTransferItems(array $data): array
    {
        return collect($data['items'])->map(function ($row) use ($data) {
            $product = $this->assertProduct($row['product_id']);
            $qty = (float) ($row['approved_quantity'] ?? $row['requested_quantity']);
            $this->stock->validateAvailableStock($this->scope($data['source_branch_id'] ?? null, $data['source_warehouse_id'], $product->id, $row['product_variant_id'] ?? null, $row['source_batch_id'] ?? null), $qty);
            return array_merge($row, ['unit_id' => $row['unit_id'] ?? $product->unit_id ?? null, 'approved_quantity' => $row['approved_quantity'] ?? $row['requested_quantity']]);
        })->all();
    }

    private function adjustmentTotals(array $items): array
    {
        $in = collect($items)->where('direction', 'in');
        $out = collect($items)->where('direction', 'out');
        return [
            'total_quantity_in' => round($in->sum('adjustment_quantity'), 3),
            'total_quantity_out' => round($out->sum('adjustment_quantity'), 3),
            'total_value_in' => round($in->sum('adjustment_value'), 2),
            'total_value_out' => round($out->sum('adjustment_value'), 2),
        ];
    }

    private function postAdjustmentAccounting(StockAdjustmentVoucher $voucher): ?JournalVoucher
    {
        if (!class_exists(JournalVoucher::class)) return null;
        $settings = BusinessInventorySetting::query()->where('business_id', $voucher->business_id)->first();
        $inventoryAccount = DB::table('business_account_settings')->where('business_id', $voucher->business_id)->value('inventory_account_id');
        if (!$inventoryAccount) return null;
        $gain = $settings->stock_adjustment_gain_account_id ?? null;
        $loss = $settings->stock_adjustment_loss_account_id ?? null;
        if (!$gain) $gain = Account::query()->where('business_id', $voucher->business_id)->where('account_type', 'income')->value('id');
        if (!$loss) $loss = Account::query()->where('business_id', $voucher->business_id)->where('account_type', 'expense')->value('id');
        $entries = [];
        $this->accounting->addDebitEntry($entries, $inventoryAccount, (float) $voucher->total_value_in);
        $this->accounting->addCreditEntry($entries, $gain, (float) $voucher->total_value_in);
        $this->accounting->addDebitEntry($entries, $loss, (float) $voucher->total_value_out);
        $this->accounting->addCreditEntry($entries, $inventoryAccount, (float) $voucher->total_value_out);
        if (!$entries) return null;
        return $this->accounting->createJournalVoucher(['business_id' => $voucher->business_id, 'branch_id' => $voucher->branch_id, 'voucher_type' => 'stock_adjustment', 'voucher_date' => $voucher->adjustment_date->format('Y-m-d'), 'reference_type' => StockAdjustmentVoucher::class, 'reference_id' => $voucher->id, 'reference_number' => $voucher->voucher_number, 'narration' => 'Stock adjustment posting', 'status' => 'approved', 'is_system_generated' => true, 'entries' => $entries]);
    }

    private function stockPayload(StockAdjustmentVoucher $voucher, $item, array $extra): array
    {
        return array_merge(['business_id' => $voucher->business_id, 'branch_id' => $voucher->branch_id, 'warehouse_id' => $voucher->warehouse_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'batch_id' => $item->batch_id, 'serial_id' => $item->serial_id, 'transaction_date' => $voucher->adjustment_date], $extra);
    }

    private function transferPayload(StockTransferVoucher $voucher, $item, float $qty, ?int $branchId, int $warehouseId, string $type, ?string $location): array
    {
        return ['business_id' => $voucher->business_id, 'branch_id' => $branchId, 'warehouse_id' => $warehouseId, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'batch_id' => $item->source_batch_id, 'transaction_type' => $type, 'reference_type' => StockTransferVoucher::class, 'reference_id' => $voucher->id, 'quantity' => $qty, 'unit_cost' => (float) $item->unit_cost, 'transaction_date' => $voucher->transfer_date, 'warehouse_location' => $location, 'stock_status' => $type === 'stock_transfer_out' ? 'in_transit' : 'saleable', 'remarks' => $voucher->voucher_number];
    }

    private function scope(?int $branchId, ?int $warehouseId, int $productId, ?int $variantId = null, ?int $batchId = null): array
    {
        return ['business_id' => AppController::businessId(), 'branch_id' => $branchId, 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'product_variant_id' => $variantId, 'batch_id' => $batchId];
    }

    private function reservedStock(array $scope): float
    {
        return (float) StockReservation::query()->where('business_id', AppController::businessId())->where('status', 'active')
            ->when(!empty($scope['branch_id']), fn (Builder $q) => $q->where('branch_id', $scope['branch_id']))
            ->when(!empty($scope['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $scope['warehouse_id']))
            ->when(!empty($scope['product_id']), fn (Builder $q) => $q->where('product_id', $scope['product_id']))
            ->sum(DB::raw('reserved_quantity - fulfilled_quantity - released_quantity'));
    }

    private function availableStock(array $scope): float
    {
        return round($this->stock->getCurrentStock($scope) - $this->reservedStock($scope), 3);
    }

    private function outTypeForCondition(?string $condition): string
    {
        if ($condition === 'damaged') return 'damaged_stock';
        if ($condition === 'expired') return 'expired_stock';
        if ($condition === 'lost') return 'lost_stock';
        return 'stock_adjustment_out';
    }

    private function stockAlreadyPosted(string $type, int $id): bool
    {
        return StockLedger::query()->where('business_id', AppController::businessId())->where('reference_type', $type)->where('reference_id', $id)->exists();
    }

    private function assertReason(int $id): StockAdjustmentReason
    {
        return StockAdjustmentReason::query()->where('business_id', AppController::businessId())->findOrFail($id);
    }

    private function assertWarehouse(int $id, ?int $branchId = null): Warehouse
    {
        return Warehouse::query()->where('business_id', AppController::businessId())->where('id', $id)->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))->firstOrFail();
    }

    private function assertProduct(int $id): Product
    {
        $businessId = AppController::businessId();
        return Product::query()->where('id', $id)->where(function (Builder $q) use ($businessId) {
            $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
        })->where('status', 'active')->firstOrFail();
    }

    private function assertAccount(int $id): Account
    {
        return Account::query()->where('business_id', AppController::businessId())->findOrFail($id);
    }

    private function nextNumber(string $prefix, string $model, string $column): string
    {
        $businessId = AppController::businessId();
        $prefix .= '-' . date('Y') . '-';
        $last = $model::query()->where('business_id', $businessId)->where($column, 'like', $prefix . '%')->lockForUpdate()->orderByDesc('id')->value($column);
        return $prefix . str_pad((string) ($last ? ((int) substr($last, strlen($prefix)) + 1) : 1), 5, '0', STR_PAD_LEFT);
    }
}
