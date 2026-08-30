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
use App\Models\ProductBatch;
use App\Models\ProductSerialNumber;
use App\Models\StockAdjustmentReason;
use App\Models\StockAdjustmentVoucher;
use App\Models\StockCountSession;
use App\Models\StockLedger;
use App\Models\StockReservation;
use App\Models\StockTransferVoucher;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryControlService
{
    private StockService $stock;
    private AccountingPostingService $accounting;
    private InventoryFreezeService $freeze;

    public function __construct(StockService $stock, AccountingPostingService $accounting, InventoryFreezeService $freeze)
    {
        $this->stock = $stock;
        $this->accounting = $accounting;
        $this->freeze = $freeze;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        $masterReferences = app(MasterDataService::class)->references(['branches', 'warehouses', 'categories', 'sub_categories', 'brands', 'units', 'hsn_codes']);

        return array_merge($masterReferences, [
            'reasons' => StockAdjustmentReason::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('reason_name')->get(),
            'statuses' => InventoryStockStatus::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(),
            'products' => Product::query()->where(function (Builder $q) use ($businessId) {
                $q->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })->where('status', 'active')->orderBy('name')->limit(200)->get($this->productReferenceColumns()),
            'settings' => BusinessInventorySetting::query()->where('business_id', $businessId)->first(),
        ]);
    }

    public function searchProducts(string $q)
    {
        $businessId = AppController::businessId();
        return Product::query()->where(function (Builder $query) use ($businessId) {
            $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
        })->where(function (Builder $query) use ($q) {
            $query->where('name', 'like', '%' . $q . '%')->orWhere('sku', 'like', '%' . $q . '%')->orWhere('primary_barcode', $q)->orWhere('barcode', $q)
                ->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', $q));
        })->where('status', 'active')->limit(20)->get($this->productReferenceColumns());
    }

    public function reasons(array $filters)
    {
        return StockAdjustmentReason::query()->with('account')->withCount('vouchers')->where('business_id', AppController::businessId())
            ->when(!empty($filters['search']), fn (Builder $q) => $q->where('reason_name', 'like', '%' . $filters['search'] . '%')->orWhere('reason_code', 'like', '%' . $filters['search'] . '%'))
            ->latest('id')->paginate(50);
    }

    public function saveReason(array $data, ?int $id = null): StockAdjustmentReason
    {
        $businessId = AppController::businessId();
        if (!empty($data['accounting_account_id'])) $this->assertAccount($data['accounting_account_id']);
        $duplicateActive = StockAdjustmentReason::query()
            ->where('business_id', $businessId)
            ->where('reason_name', $data['reason_name'])
            ->where('default_direction', $data['default_direction'])
            ->where('default_condition_status', $data['default_condition_status'])
            ->where('status', 'active')
            ->when($id, fn (Builder $query) => $query->where('id', '!=', $id))
            ->exists();
        if (($data['status'] ?? 'active') === 'active' && $duplicateActive) {
            throw ValidationException::withMessages(['reason_name' => 'An active reason with the same name, movement and condition already exists.']);
        }
        $reason = $id ? StockAdjustmentReason::query()->where('business_id', $businessId)->findOrFail($id) : new StockAdjustmentReason(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $reason->fill(array_merge($data, ['updated_by' => Auth::id()]))->save();
        return $reason->fresh('account')->loadCount('vouchers');
    }

    public function seedDefaultReasons(): int
    {
        $businessId = AppController::businessId();
        if (StockAdjustmentReason::query()->where('business_id', $businessId)->exists()) {
            throw ValidationException::withMessages(['reasons' => 'Default reasons can be created only when no reasons exist.']);
        }

        $defaults = [
            ['DAMAGE', 'Damaged Stock', 'out', 'damaged'],
            ['EXPIRED', 'Expired Stock', 'out', 'expired'],
            ['LOST', 'Lost / Missing Stock', 'out', 'lost'],
            ['DEFECT', 'Defective Stock', 'out', 'defective'],
            ['INTUSE', 'Internal Consumption', 'out', 'saleable'],
            ['FOUND', 'Found Stock', 'in', 'saleable'],
            ['OPNCOR', 'Opening Correction', 'in', 'saleable'],
            ['PHYGAIN', 'Physical Count Gain', 'in', 'saleable'],
            ['PHYSHORT', 'Physical Count Shortage', 'out', 'saleable'],
            ['OTHER', 'Other Adjustment', 'out', 'saleable'],
        ];

        foreach ($defaults as [$code, $name, $direction, $condition]) {
            StockAdjustmentReason::query()->create([
                'business_id' => $businessId,
                'reason_code' => $code,
                'reason_name' => $name,
                'default_direction' => $direction,
                'default_condition_status' => $condition,
                'approval_required' => true,
                'is_system' => false,
                'status' => 'active',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
        }

        return count($defaults);
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
        return StockAdjustmentVoucher::query()->with(['branch', 'warehouse', 'reason', 'items.product', 'items.batch'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['source']), fn (Builder $q) => $q->where('source', $filters['source']))
            ->when(!empty($filters['voucher_type']), fn (Builder $q) => $q->where('source', $filters['voucher_type']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['reason']), fn (Builder $q) => $q->whereHas('reason', fn (Builder $r) => $r->where('reason_name', 'like', '%' . $filters['reason'] . '%')))
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('adjustment_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('adjustment_date', '<=', $filters['date_to']))
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $search = '%' . $filters['search'] . '%';
                $q->where(function (Builder $query) use ($search) {
                    $query->where('voucher_number', 'like', $search)
                        ->orWhere('remarks', 'like', $search)
                        ->orWhereHas('reason', fn (Builder $reason) => $reason->where('reason_name', 'like', $search))
                        ->orWhereHas('items.product', fn (Builder $product) => $product->where('name', 'like', $search)->orWhere('sku', 'like', $search));
                });
            })
            ->latest('id')->paginate(20);
    }

    public function saveAdjustment(array $data, ?int $id = null): StockAdjustmentVoucher
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->assertWarehouse($data['warehouse_id'], $data['branch_id'] ?? null);
            if (!empty($data['adjustment_reason_id'])) $this->assertReason($data['adjustment_reason_id']);
            $items = $this->prepareAdjustmentItems($data);
            $this->validateAdjustmentVoucher($data, $items);
            $skipFreezeCheck = !empty($data['skip_freeze_check']);
            unset($data['items']);
            unset($data['skip_freeze_check']);
            $voucher = $id ? StockAdjustmentVoucher::query()->where('business_id', $businessId)->with('items')->findOrFail($id) : new StockAdjustmentVoucher(['business_id' => $businessId, 'voucher_number' => $this->nextNumber('ADJ', StockAdjustmentVoucher::class, 'voucher_number'), 'created_by' => Auth::id()]);
            if (in_array($voucher->status, ['posted', 'reversed', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Posted stock adjustments cannot be edited.']);
            $totals = $this->adjustmentTotals($items);
            $items = collect($items)->map(function (array $item) {
                unset($item['source_condition_quantity']);
                return $item;
            })->all();
            $voucher->fill(array_merge($data, $totals))->save();
            $voucher->items()->delete();
            $voucher->items()->createMany($items);
            if (in_array($data['status'], ['approved', 'posted'], true)) $this->postAdjustment($voucher->id, $skipFreezeCheck);
            return $voucher->fresh(['items.product', 'warehouse', 'reason']);
        });
    }

    public function postAdjustment(int $id, bool $skipFreezeCheck = false): StockAdjustmentVoucher
    {
        return DB::transaction(function () use ($id, $skipFreezeCheck) {
            $voucher = StockAdjustmentVoucher::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($id);
            if ($this->stockAlreadyPosted(StockAdjustmentVoucher::class, $voucher->id)) return $voucher;
            $this->validateAdjustmentVoucher($voucher->toArray(), $voucher->items->map(fn ($item) => $item->toArray())->all(), true);
            $isCountPosting = $skipFreezeCheck && $voucher->source === 'physical_count';
            if (!$isCountPosting) {
                $this->freeze->assertWarehouseAvailable($voucher->branch_id, $voucher->warehouse_id, $voucher->business_id);
            }
            foreach ($voucher->items as $item) {
                $isConditionTransfer = $voucher->adjustment_type === 'condition_transfer' || $item->direction === 'transfer';
                $condition = $this->adjustmentLineCondition($item->toArray(), $isConditionTransfer);
                $basePayload = $this->stockPayload($voucher, $item, [
                    'reference_type' => StockAdjustmentVoucher::class,
                    'reference_id' => $voucher->id,
                    'quantity' => (float) $item->adjustment_quantity,
                    'unit_cost' => (float) $item->unit_cost,
                    'warehouse_location' => $item->warehouse_location,
                    'remarks' => $item->reason ?: $voucher->remarks,
                    'skip_freeze_check' => $isCountPosting,
                ]);

                if ($isConditionTransfer) {
                    $fromCondition = $item->source_condition_status ?: 'saleable';
                    $toCondition = $item->destination_condition_status ?: $condition;
                    $movement = str($fromCondition)->replace('_', ' ')->title() . ' -> ' . str($toCondition)->replace('_', ' ')->title();

                    $this->stock->decreaseStock($basePayload + [
                        'transaction_type' => 'stock_reclassification_out',
                        'stock_status' => $fromCondition,
                        'remarks' => trim(($item->reason ?: $voucher->remarks ?: '') . ' ' . $movement),
                    ]);
                    $this->stock->increaseStock($basePayload + [
                        'transaction_type' => 'stock_reclassification_in',
                        'stock_status' => $toCondition,
                        'remarks' => trim(($item->reason ?: $voucher->remarks ?: '') . ' ' . $movement),
                    ]);
                    continue;
                }

                $payload = $basePayload + [
                    'transaction_type' => $item->direction === 'in' ? 'stock_adjustment_in' : $this->outTypeForCondition($condition),
                    'stock_status' => $condition,
                ];
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
        return StockCountSession::query()->with(['branch', 'warehouse', 'items.product'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['count_type']), fn (Builder $q) => $q->where('count_type', $filters['count_type']))
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('count_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('count_date', '<=', $filters['date_to']))
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $search = '%' . $filters['search'] . '%';
                $q->where(function (Builder $query) use ($search) {
                    $query->where('session_number', 'like', $search)
                        ->orWhere('remarks', 'like', $search)
                        ->orWhereHas('items.product', fn (Builder $product) => $product->where('name', 'like', $search)->orWhere('sku', 'like', $search));
                });
            })
            ->latest('id')->paginate(20);
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

    public function postCountVariance(int $sessionId)
    {
        return DB::transaction(function () use ($sessionId) {
            $session = StockCountSession::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($sessionId);
            if ($session->status === 'posted') {
                throw ValidationException::withMessages(['status' => 'This stock count has already been posted.']);
            }
            if ($this->stockAlreadyPosted(StockCountSession::class, $session->id)) {
                $session->update(['status' => 'posted', 'approved_by' => Auth::id(), 'approved_at' => now(), 'completed_at' => now()]);
                return null;
            }
            if (!$session->items->count()) {
                throw ValidationException::withMessages(['items' => 'Stock count must have at least one item before posting.']);
            }
            $items = $session->items->filter(fn ($i) => $i->review_status === 'accepted' && round((float) $i->variance_quantity, 3) != 0.0)->map(fn ($i) => [
                'product_id' => $i->product_id, 'product_variant_id' => $i->product_variant_id, 'batch_id' => $i->batch_id,
                'unit_id' => null, 'adjustment_quantity' => abs((float) $i->variance_quantity), 'direction' => (float) $i->variance_quantity > 0 ? 'in' : 'out',
                'unit_cost' => (float) $i->unit_cost, 'warehouse_location' => $i->warehouse_location, 'condition_status' => 'saleable',
                'reason' => $i->reviewer_notes ?: ((float) $i->variance_quantity > 0 ? 'Physical Count Gain' : 'Physical Count Shortage'), 'actual_quantity' => $i->counted_quantity,
            ])->values()->all();
            if (!$items) {
                $session->update(['status' => 'posted', 'approved_by' => Auth::id(), 'approved_at' => now(), 'completed_at' => now()]);
                return null;
            }
            $voucher = $this->saveAdjustment([
                'branch_id' => $session->branch_id, 'warehouse_id' => $session->warehouse_id, 'adjustment_date' => now()->toDateString(),
                'adjustment_reason_id' => null, 'adjustment_type' => 'mixed', 'source' => 'physical_count', 'status' => 'posted',
                'remarks' => 'Stock Count ' . $session->session_number, 'skip_freeze_check' => true, 'items' => $items,
            ]);
            $this->mapCountAdjustmentLedgerToSession($voucher, $session);
            $session->update(['status' => 'posted', 'approved_by' => Auth::id(), 'approved_at' => now(), 'completed_at' => now()]);
            return $voucher;
        });
    }

    public function transfers(array $filters)
    {
        return StockTransferVoucher::query()->with(['sourceWarehouse', 'destinationWarehouse', 'sourceBranch', 'destinationBranch', 'items.product', 'items.sourceBatch', 'items.destinationBatch', 'items.sourceSerial', 'items.destinationSerial'])->where('business_id', AppController::businessId())
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where(fn (Builder $query) => $query->where('source_branch_id', $filters['branch_id'])->orWhere('destination_branch_id', $filters['branch_id'])))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where(fn (Builder $query) => $query->where('source_warehouse_id', $filters['warehouse_id'])->orWhere('destination_warehouse_id', $filters['warehouse_id'])))
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('transfer_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('transfer_date', '<=', $filters['date_to']))
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $search = '%' . $filters['search'] . '%';
                $q->where(function (Builder $query) use ($search) {
                    $query->where('voucher_number', 'like', $search)
                        ->orWhere('remarks', 'like', $search)
                        ->orWhereHas('items.product', fn (Builder $product) => $product->where('name', 'like', $search)->orWhere('sku', 'like', $search));
                });
            })
            ->latest('id')->paginate(20);
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
            if (in_array($voucher->status, ['posted', 'received', 'reversed', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Finalized transfers cannot be edited.']);
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
            $this->freeze->assertWarehousesAvailable([
                ['branch_id' => $voucher->source_branch_id, 'warehouse_id' => $voucher->source_warehouse_id],
                ['branch_id' => $voucher->destination_branch_id, 'warehouse_id' => $voucher->destination_warehouse_id],
            ], $voucher->business_id);
            foreach ($voucher->items as $item) {
                $qty = (float) ($item->approved_quantity ?: $item->requested_quantity);
                $sourceScope = $this->scope($voucher->source_branch_id, $voucher->source_warehouse_id, (int) $item->product_id, $item->product_variant_id, $item->source_batch_id);
                $this->stock->validateAvailableStock(array_merge($sourceScope, ['stock_status' => 'saleable']), $qty);
                $this->stock->decreaseStock($this->transferPayload($voucher, $item, $qty, $voucher->source_branch_id, $voucher->source_warehouse_id, 'stock_transfer_out', $item->source_location, $item->source_batch_id, $item->source_serial_id));
                $this->stock->increaseStock($this->transferPayload($voucher, $item, $qty, $voucher->destination_branch_id, $voucher->destination_warehouse_id, 'stock_transfer_in', $item->destination_location, $item->destination_batch_id ?: $item->source_batch_id, $item->destination_serial_id ?: $item->source_serial_id));
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
            $this->freeze->assertWarehouseAvailable($voucher->source_branch_id, $voucher->source_warehouse_id, $voucher->business_id);
            foreach ($voucher->items as $item) {
                $qty = (float) ($item->approved_quantity ?: $item->requested_quantity);
                $sourceScope = $this->scope($voucher->source_branch_id, $voucher->source_warehouse_id, (int) $item->product_id, $item->product_variant_id, $item->source_batch_id);
                $this->stock->validateAvailableStock(array_merge($sourceScope, ['stock_status' => 'saleable']), $qty);
                $this->stock->decreaseStock($this->transferPayload($voucher, $item, $qty, $voucher->source_branch_id, $voucher->source_warehouse_id, 'stock_transfer_out', $item->source_location, $item->source_batch_id, $item->source_serial_id));
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
            $this->freeze->assertWarehouseAvailable($voucher->destination_branch_id, $voucher->destination_warehouse_id, $voucher->business_id);
            foreach ($data['items'] as $row) {
                $item = $voucher->items->firstWhere('id', (int) $row['id']);
                if (!$item) continue;
                $already = (float) $item->received_quantity;
                $qty = (float) $row['received_quantity'];
                $rejected = (float) ($row['rejected_quantity'] ?? 0);
                if ($qty + $already > (float) $item->dispatched_quantity) throw ValidationException::withMessages(['received_quantity' => 'Received quantity cannot exceed dispatched quantity.']);
                if ($qty > 0) $this->stock->increaseStock($this->transferPayload($voucher, $item, $qty, $voucher->destination_branch_id, $voucher->destination_warehouse_id, 'stock_transfer_in', $row['destination_location'] ?? $item->destination_location, $item->destination_batch_id ?: $item->source_batch_id, $item->destination_serial_id ?: $item->source_serial_id));
                $item->update(['received_quantity' => $already + $qty, 'rejected_quantity' => (float) $item->rejected_quantity + $rejected]);
            }
            $allReceived = $voucher->items()->get()->every(fn ($i) => (float) $i->received_quantity >= (float) $i->dispatched_quantity);
            $voucher->update(['status' => $allReceived ? 'received' : 'partially_received', 'received_by' => Auth::id(), 'received_at' => now()]);
            return $voucher->fresh('items');
        });
    }

    public function locationTransfers(array $filters)
    {
        return LocationTransferVoucher::query()->with(['warehouse', 'items.product'])->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function warehouseLocations(array $filters)
    {
        return WarehouseLocation::query()->with('warehouse')->where('business_id', AppController::businessId())
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $search = '%' . $filters['search'] . '%';
                $q->where(fn (Builder $query) => $query->where('rack', 'like', $search)->orWhere('shelf', 'like', $search)->orWhere('bin', 'like', $search)->orWhere('zone', 'like', $search)->orWhere('aisle', 'like', $search));
            })
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 50));
    }

    public function saveWarehouseLocation(array $data, ?int $id = null): WarehouseLocation
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $warehouse = $this->assertWarehouse((int) $data['warehouse_id'], $data['branch_id'] ?? null);
            $location = $id ? WarehouseLocation::query()->where('business_id', $businessId)->findOrFail($id) : new WarehouseLocation(['business_id' => $businessId]);
            $location->fill(array_merge($data, ['branch_id' => $data['branch_id'] ?? $warehouse->branch_id]))->save();

            return $location->fresh('warehouse');
        });
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
        $dashboard = $this->stock->dashboard($filters);

        return [
            'total_stock_value' => $dashboard['inventory_value'] ?? 0,
            'total_saleable_quantity' => $dashboard['saleable_quantity'] ?? $dashboard['total_quantity'] ?? 0,
            'damaged_quantity' => $dashboard['damaged_quantity'] ?? 0,
            'expired_quantity' => $dashboard['expired_quantity'] ?? 0,
            'defective_quantity' => $dashboard['defective_quantity'] ?? 0,
            'quarantined_quantity' => $dashboard['quarantined_quantity'] ?? 0,
            'lost_quantity' => $dashboard['lost_quantity'] ?? 0,
            'physical_quantity' => $dashboard['physical_quantity'] ?? $dashboard['total_quantity'] ?? 0,
            'non_saleable_quantity' => $dashboard['non_saleable_quantity'] ?? 0,
            'low_stock_items' => $dashboard['low_stock_products'] ?? 0,
            'out_of_stock_items' => $dashboard['out_of_stock_products'] ?? 0,
            'near_expiry_items' => $this->ledgerQuery($filters)->join('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')->whereBetween('product_batches.expiry_date', [now(), now()->addDays(30)])->distinct('stock_ledgers.batch_id')->count('stock_ledgers.batch_id'),
            'expired_items' => $this->ledgerQuery($filters)->join('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')->whereDate('product_batches.expiry_date', '<', now())->distinct('stock_ledgers.batch_id')->count('stock_ledgers.batch_id'),
            'damaged_stock_value' => (float) $this->ledgerQuery($filters)->where('stock_status', 'damaged')->sum('stock_value'),
            'stock_in_transit' => (float) $this->ledgerQuery($filters)->where('stock_status', 'in_transit')->selectRaw('COALESCE(SUM(quantity_in - quantity_out), 0) as qty')->value('qty'),
            'pending_stock_counts' => StockCountSession::query()->where('business_id', $businessId)->whereIn('status', ['draft', 'assigned', 'counting', 'submitted', 'reviewed'])->count(),
            'pending_transfer_receipts' => StockTransferVoucher::query()->where('business_id', $businessId)->whereIn('status', ['dispatched', 'partially_received'])->count(),
            'stock_adjustment_value' => (float) StockAdjustmentVoucher::query()->where('business_id', $businessId)->where('status', 'posted')->sum(DB::raw('total_value_in + total_value_out')),
        ];
    }

    public function reports(array $filters): array
    {
        $businessId = AppController::businessId();
        $ledger = StockLedger::query()->with(['product', 'warehouse', 'branch', 'creator'])->where('business_id', $businessId)
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('transaction_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('transaction_date', '<=', $filters['date_to']))
            ->when(!empty($filters['search']), fn (Builder $q) => $this->applyLedgerRegisterSearch($q, (string) $filters['search']))
            ->when(!empty($filters['voucher_type']), fn (Builder $q) => $this->applyLedgerRegisterType($q, (string) $filters['voucher_type']))
            ->orderByDesc(DB::raw('COALESCE(stock_ledgers.posted_at, stock_ledgers.created_at)'))
            ->limit(200)
            ->get();
        $references = $this->stock->stockReferenceNumbers($ledger);
        $ledger->each(function (StockLedger $entry) use ($references) {
            $entry->reference_number = $references[$entry->reference_type . ':' . $entry->reference_id] ?? (string) $entry->reference_id;
            $entry->product_name = $entry->product?->name;
            $entry->user_name = $entry->creator?->name ?: ($entry->created_by ? 'User' : 'System');
        });
        $this->annotatePhysicalCountLedger($ledger);
        $movementReport = $this->logicalMovementReport($ledger);
        $batchNumberColumn = Schema::hasColumn('product_batches', 'batch_number') ? 'product_batches.batch_number' : 'product_batches.batch_no';

        return [
            'stock_summary' => $this->stock->summary($filters),
            'ledger' => $ledger,
            'movement_report' => $movementReport,
            'inventory_valuation' => $this->stock->summary(array_merge($filters, ['view_mode' => 'summary', 'per_page' => 100]))->getCollection()->values(),
            'branch_report' => $this->conditionReportQuery($filters)->leftJoin('branches', 'branches.id', '=', 'stock_ledgers.branch_id')->selectRaw($this->conditionReportSelect('COALESCE(branches.name, "Unassigned") as branch_name'))->groupBy('branches.name')->orderBy('branches.name')->get(),
            'warehouse_report' => $this->conditionReportQuery($filters)->leftJoin('warehouses', 'warehouses.id', '=', 'stock_ledgers.warehouse_id')->selectRaw($this->conditionReportSelect('COALESCE(warehouses.name, "Unassigned") as warehouse_name'))->groupBy('warehouses.name')->orderBy('warehouses.name')->get(),
            'adjustment_report' => StockAdjustmentVoucher::query()->with(['branch', 'warehouse', 'reason'])->where('business_id', $businessId)->latest('id')->limit(100)->get(),
            'transfer_report' => StockTransferVoucher::query()->with(['sourceWarehouse', 'destinationWarehouse'])->where('business_id', $businessId)->latest('id')->limit(100)->get(),
            'variance_report' => StockCountSession::query()->with('items.product')->where('business_id', $businessId)->latest('id')->limit(50)->get(),
            'damage_report' => StockLedger::query()->with(['product', 'warehouse', 'branch'])->where('business_id', $businessId)->where(fn (Builder $q) => $q->where('transaction_type', 'damaged_stock')->orWhere('stock_status', 'damaged'))->orderByDesc(DB::raw('COALESCE(stock_ledgers.posted_at, stock_ledgers.created_at)'))->limit(100)->get(),
            'expiry_report' => StockLedger::query()->with(['product', 'warehouse', 'branch'])->where('business_id', $businessId)->where(fn (Builder $q) => $q->where('transaction_type', 'expired_stock')->orWhere('stock_status', 'expired'))->orderByDesc(DB::raw('COALESCE(stock_ledgers.posted_at, stock_ledgers.created_at)'))->limit(100)->get(),
            'batch_report' => DB::table('stock_ledgers')->join('products', 'products.id', '=', 'stock_ledgers.product_id')->leftJoin('product_batches', 'product_batches.id', '=', 'stock_ledgers.batch_id')->where('stock_ledgers.business_id', $businessId)->selectRaw("products.name as product_name, {$batchNumberColumn} as batch_number, product_batches.expiry_date, SUM(quantity_in) as quantity_in, SUM(quantity_out) as quantity_out, SUM(quantity_in - quantity_out) as available_quantity, SUM(stock_value) as stock_value")->groupBy('products.name', DB::raw($batchNumberColumn), 'product_batches.expiry_date')->limit(100)->get(),
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
            $isConditionTransfer = ($data['adjustment_type'] ?? null) === 'condition_transfer' || ($row['direction'] ?? null) === 'transfer';
            $lineCondition = $this->adjustmentLineCondition($row, $isConditionTransfer);
            $sourceCondition = $isConditionTransfer ? ($row['source_condition_status'] ?? $lineCondition) : $lineCondition;
            $destinationCondition = $row['destination_condition_status'] ?? $lineCondition;
            $systemQty = $this->stock->getConditionStock($scope, $isConditionTransfer ? $sourceCondition : $lineCondition);
            $sourceQty = $this->stock->getConditionStock($scope, $sourceCondition);
            if (($row['direction'] ?? null) === 'out') {
                $this->stock->validateAvailableStock(array_merge($scope, ['stock_status' => $lineCondition]), (float) $row['adjustment_quantity']);
            }
            return array_merge($row, [
                'unit_id' => $row['unit_id'] ?? $product->unit_id ?? null,
                'system_quantity' => $systemQty,
                'adjustment_value' => round((float) $row['adjustment_quantity'] * (float) $row['unit_cost'], 2),
                'condition_status' => $isConditionTransfer ? $destinationCondition : $lineCondition,
                'source_condition_status' => ($isConditionTransfer || ($row['direction'] ?? null) === 'out') ? $sourceCondition : null,
                'destination_condition_status' => $isConditionTransfer ? $destinationCondition : null,
                'source_condition_quantity' => round((float) $sourceQty, 3),
            ]);
        })->all();
    }

    private function validateAdjustmentVoucher(array $data, array $items, bool $posting = false): void
    {
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => 'At least one product line is required.']);
        }

        if (empty($data['warehouse_id'])) {
            throw ValidationException::withMessages(['warehouse_id' => 'Source warehouse is required.']);
        }

        $isDraft = ($data['status'] ?? 'draft') === 'draft' && !$posting;
        if (!$isDraft && empty($data['adjustment_reason_id']) && empty($data['remarks'])) {
            throw ValidationException::withMessages(['adjustment_reason_id' => 'Reason is required before posting.']);
        }

        $reason = !empty($data['adjustment_reason_id']) ? $this->assertReason((int) $data['adjustment_reason_id']) : null;
        $serials = [];

        foreach ($items as $index => $item) {
            $quantity = (float) ($item['adjustment_quantity'] ?? 0);
            if ($quantity <= 0) {
                throw ValidationException::withMessages(["items.$index.adjustment_quantity" => 'Quantity must be greater than zero.']);
            }

            $isConditionTransfer = ($data['adjustment_type'] ?? null) === 'condition_transfer' || ($item['direction'] ?? null) === 'transfer';

            if (!in_array($item['direction'] ?? '', ['in', 'out', 'transfer'], true)) {
                throw ValidationException::withMessages(["items.$index.direction" => 'Direction must be IN or OUT.']);
            }

            if ($isConditionTransfer) {
                $sourceCondition = $item['source_condition_status'] ?? null;
                $destinationCondition = $item['destination_condition_status'] ?? null;

                if (!$sourceCondition) {
                    throw ValidationException::withMessages(["items.$index.source_condition_status" => 'From condition is required.']);
                }

                if (!$destinationCondition) {
                    throw ValidationException::withMessages(["items.$index.destination_condition_status" => 'To condition is required.']);
                }

                if ($sourceCondition === $destinationCondition) {
                    throw ValidationException::withMessages(["items.$index.destination_condition_status" => 'From and To condition cannot be the same.']);
                }

                $sourceQty = (float) ($item['source_condition_quantity'] ?? $this->stock->getConditionStock(
                    $this->scope($data['branch_id'] ?? null, $data['warehouse_id'] ?? null, (int) ($item['product_id'] ?? 0), $item['product_variant_id'] ?? null, $item['batch_id'] ?? null),
                    $sourceCondition
                ));

                if ($sourceQty + 0.0004 < $quantity) {
                    throw ValidationException::withMessages([
                        "items.$index.adjustment_quantity" => 'Only ' . round($sourceQty, 3) . ' units are available in ' . str($sourceCondition)->replace('_', ' ')->title() . ' stock.',
                    ]);
                }
            } elseif (($item['direction'] ?? null) === 'out') {
                $condition = $this->adjustmentLineCondition($item, false);
                $available = $this->stock->getConditionStock(
                    $this->scope($data['branch_id'] ?? null, $data['warehouse_id'] ?? null, (int) ($item['product_id'] ?? 0), $item['product_variant_id'] ?? null, $item['batch_id'] ?? null),
                    $condition
                );

                if ($available + 0.0004 < $quantity) {
                    throw ValidationException::withMessages([
                        "items.$index.adjustment_quantity" => 'Only ' . round($available, 3) . ' units are available in ' . str($condition)->replace('_', ' ')->title() . ' stock.',
                    ]);
                }
            }

            if (!$isConditionTransfer && $reason && ($item['direction'] ?? null) !== $reason->default_direction) {
                throw ValidationException::withMessages(["items.$index.direction" => "{$reason->reason_name} requires {$reason->default_direction} movement."]);
            }

            if (!empty($item['serial_id'])) {
                $serialKey = implode(':', [$item['product_id'] ?? 0, $item['serial_id']]);
                if (isset($serials[$serialKey])) {
                    throw ValidationException::withMessages(["items.$index.serial_id" => 'Duplicate serial number is not allowed in the same voucher.']);
                }
                $serials[$serialKey] = true;
            }
        }
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
        $serials = [];

        return collect($data['items'])->map(function ($row, int $index) use ($data, &$serials) {
            $product = $this->assertProduct($row['product_id']);
            $qty = (float) ($row['approved_quantity'] ?? $row['requested_quantity']);
            if ($qty <= 0) {
                throw ValidationException::withMessages(["items.$index.requested_quantity" => 'Quantity must be greater than zero.']);
            }

            if ((int) $data['source_warehouse_id'] === (int) $data['destination_warehouse_id']) {
                throw ValidationException::withMessages(['destination_warehouse_id' => 'Source and destination warehouse cannot be same.']);
            }

            if (!empty($row['source_batch_id'])) {
                $this->assertBatch((int) $row['source_batch_id'], $product->id);
            }

            if (!empty($row['destination_batch_id'])) {
                $this->assertBatch((int) $row['destination_batch_id'], $product->id);
            }

            if (!empty($row['source_serial_id'])) {
                $serial = $this->assertSerial((int) $row['source_serial_id'], $product->id);
                $serialKey = implode(':', [$product->id, $serial->id]);
                if (isset($serials[$serialKey])) {
                    throw ValidationException::withMessages(["items.$index.source_serial_id" => 'Duplicate serial number is not allowed in the same transfer.']);
                }
                $serials[$serialKey] = true;
            }

            if (!empty($row['destination_serial_id'])) {
                $this->assertSerial((int) $row['destination_serial_id'], $product->id);
            }

            $this->stock->validateAvailableStock(array_merge($this->scope($data['source_branch_id'] ?? null, $data['source_warehouse_id'], $product->id, $row['product_variant_id'] ?? null, $row['source_batch_id'] ?? null), ['stock_status' => 'saleable']), $qty);

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
        if ($voucher->adjustment_type === 'condition_transfer') return null;
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

    private function ledgerQuery(array $filters): Builder
    {
        return StockLedger::query()
            ->where('stock_ledgers.business_id', AppController::businessId())
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('stock_ledgers.branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('stock_ledgers.warehouse_id', $filters['warehouse_id']));
    }

    private function transferPayload(StockTransferVoucher $voucher, $item, float $qty, ?int $branchId, int $warehouseId, string $type, ?string $location, ?int $batchId = null, ?int $serialId = null): array
    {
        return ['business_id' => $voucher->business_id, 'branch_id' => $branchId, 'warehouse_id' => $warehouseId, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'batch_id' => $batchId, 'serial_id' => $serialId, 'transaction_type' => $type, 'reference_type' => StockTransferVoucher::class, 'reference_id' => $voucher->id, 'quantity' => $qty, 'unit_cost' => (float) $item->unit_cost, 'transaction_date' => $voucher->transfer_date, 'warehouse_location' => $location, 'stock_status' => 'saleable', 'remarks' => $voucher->voucher_number];
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

    private function conditionReportSelect(string $labelSelect): string
    {
        return $labelSelect . ',
            SUM(quantity_in) as quantity_in,
            SUM(quantity_out) as quantity_out,
            SUM(CASE WHEN COALESCE(stock_status, "saleable") <> "lost" THEN quantity_in - quantity_out ELSE 0 END) as physical_quantity,
            SUM(CASE WHEN COALESCE(stock_status, "saleable") = "saleable" THEN quantity_in - quantity_out ELSE 0 END) as saleable_quantity,
            SUM(CASE WHEN COALESCE(stock_status, "saleable") NOT IN ("saleable", "lost") THEN quantity_in - quantity_out ELSE 0 END) as non_saleable_quantity,
            SUM(CASE WHEN stock_status = "damaged" THEN quantity_in - quantity_out ELSE 0 END) as damaged_quantity,
            SUM(CASE WHEN stock_status = "expired" THEN quantity_in - quantity_out ELSE 0 END) as expired_quantity,
            SUM(CASE WHEN stock_status = "defective" THEN quantity_in - quantity_out ELSE 0 END) as defective_quantity,
            SUM(CASE WHEN stock_status = "quarantined" THEN quantity_in - quantity_out ELSE 0 END) as quarantined_quantity,
            SUM(CASE WHEN stock_status = "lost" THEN quantity_in - quantity_out ELSE 0 END) as lost_quantity,
            SUM(quantity_in - quantity_out) as quantity_available,
            SUM(stock_value) as stock_value';
    }

    private function conditionReportQuery(array $filters)
    {
        return DB::table('stock_ledgers')
            ->where('stock_ledgers.business_id', AppController::businessId())
            ->when(!empty($filters['branch_id']), fn ($q) => $q->where('stock_ledgers.branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn ($q) => $q->where('stock_ledgers.warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['product_id']), fn ($q) => $q->where('stock_ledgers.product_id', $filters['product_id']))
            ->when(array_key_exists('product_variant_id', $filters) && $filters['product_variant_id'] !== '', fn ($q) => $q->where('stock_ledgers.product_variant_id', $filters['product_variant_id']))
            ->when(array_key_exists('batch_id', $filters) && $filters['batch_id'] !== '', fn ($q) => $q->where('stock_ledgers.batch_id', $filters['batch_id']));
    }

    private function outTypeForCondition(?string $condition): string
    {
        if ($condition === 'damaged') return 'damaged_stock';
        if ($condition === 'expired') return 'expired_stock';
        if ($condition === 'lost') return 'lost_stock';
        return 'stock_adjustment_out';
    }

    private function adjustmentLineCondition(array $item, bool $isConditionTransfer = false): string
    {
        if ($isConditionTransfer) {
            return $item['destination_condition_status'] ?? $item['condition_status'] ?? 'saleable';
        }

        return $item['condition_status'] ?? 'saleable';
    }

    private function isConditionReclassification(?string $condition, StockAdjustmentVoucher $voucher): bool
    {
        $reasonText = strtolower(trim(($voucher->reason?->reason_code ?? '') . ' ' . ($voucher->reason?->reason_name ?? '') . ' ' . ($voucher->remarks ?? '')));
        if (strpos($reasonText, 'scrap') !== false || strpos($reasonText, 'disposal') !== false || strpos($reasonText, 'write-off') !== false || strpos($reasonText, 'write off') !== false) {
            return false;
        }

        return in_array($condition, ['damaged', 'expired', 'defective', 'quarantined'], true);
    }

    private function isPhysicalConditionOut(?string $condition, array $data): bool
    {
        if (!in_array($condition, ['damaged', 'expired', 'defective', 'quarantined', 'lost'], true)) {
            return false;
        }

        $reason = null;
        if (!empty($data['adjustment_reason_id'])) {
            $reason = StockAdjustmentReason::query()->where('business_id', AppController::businessId())->find($data['adjustment_reason_id']);
        }

        $reasonText = strtolower(trim(($reason?->reason_code ?? '') . ' ' . ($reason?->reason_name ?? '') . ' ' . ($data['remarks'] ?? '') . ' ' . ($data['source'] ?? '')));

        return str_contains($reasonText, 'scrap')
            || str_contains($reasonText, 'disposal')
            || str_contains($reasonText, 'write-off')
            || str_contains($reasonText, 'write off');
    }

    private function logicalMovementReport($ledger)
    {
        $entries = $ledger->values();
        $rows = collect();
        $used = [];

        foreach ($entries as $index => $entry) {
            if (isset($used[$index])) {
                continue;
            }

            $pairIndex = null;
            if (
                $entry->reference_type === StockAdjustmentVoucher::class
                && in_array($entry->transaction_type, ['stock_reclassification_in', 'stock_reclassification_out'], true)
            ) {
                foreach ($entries as $candidateIndex => $candidate) {
                    if ($candidateIndex === $index || isset($used[$candidateIndex])) {
                        continue;
                    }

                    if (
                        $candidate->reference_type === $entry->reference_type
                        && (int) $candidate->reference_id === (int) $entry->reference_id
                        && (int) $candidate->product_id === (int) $entry->product_id
                        && (int) ($candidate->warehouse_id ?? 0) === (int) ($entry->warehouse_id ?? 0)
                        && (int) ($candidate->branch_id ?? 0) === (int) ($entry->branch_id ?? 0)
                        && (int) ($candidate->batch_id ?? 0) === (int) ($entry->batch_id ?? 0)
                        && (int) ($candidate->product_variant_id ?? 0) === (int) ($entry->product_variant_id ?? 0)
                        && $candidate->transaction_type !== $entry->transaction_type
                        && round(abs(((float) $candidate->quantity_in + (float) $entry->quantity_in) - ((float) $candidate->quantity_out + (float) $entry->quantity_out)), 3) === 0.0
                    ) {
                        $pairIndex = $candidateIndex;
                        break;
                    }
                }
            }

            if ($pairIndex === null) {
                $row = clone $entry;
                $signedQuantity = (float) $entry->quantity_in - (float) $entry->quantity_out;
                $condition = str($entry->stock_status ?: 'saleable')->replace('_', ' ')->title();
                $row->display_quantity = $signedQuantity;
                $row->movement = $entry->movement ?: ($signedQuantity < 0 ? $condition . ' -> Out' : 'In -> ' . $condition);
                $row->physical_change = $signedQuantity;
                $rows->push($row);
                continue;
            }

            $pair = $entries[$pairIndex];
            $out = (float) $entry->quantity_out > 0 ? $entry : $pair;
            $in = (float) $entry->quantity_in > 0 ? $entry : $pair;
            $used[$pairIndex] = true;

            $row = clone $entry;
            $row->transaction_type = $in->stock_status === 'saleable' ? 'Recovered / Repaired Stock' : 'Stock Reclassification';
            $row->stock_status = $in->stock_status;
            $row->movement = str($out->stock_status ?: 'saleable')->replace('_', ' ')->title() . ' -> ' . str($in->stock_status ?: 'saleable')->replace('_', ' ')->title();
            $row->quantity_in = 0;
            $row->quantity_out = 0;
            $row->display_quantity = max((float) $out->quantity_out, (float) $in->quantity_in);
            $row->physical_change = 0;
            $row->remarks = trim(($entry->remarks ?: '') . ' ' . $row->movement);
            $rows->push($row);
        }

        return $rows->values();
    }

    private function annotatePhysicalCountLedger($ledger): void
    {
        $directCountIds = $ledger
            ->filter(fn ($entry) => $entry->reference_type === StockCountSession::class && $entry->reference_id)
            ->pluck('reference_id')
            ->unique()
            ->values();

        $directCounts = $directCountIds->isEmpty()
            ? collect()
            : StockCountSession::query()
                ->where('business_id', AppController::businessId())
                ->whereIn('id', $directCountIds)
                ->with('items')
                ->get()
                ->keyBy('id');

        if ($directCounts->isNotEmpty()) {
            $ledger->each(function (StockLedger $entry) use ($directCounts) {
                if ($entry->reference_type !== StockCountSession::class) {
                    return;
                }

                $count = $directCounts->get((int) $entry->reference_id);
                if (!$count) {
                    return;
                }

                $this->applyCountLedgerMetadata($entry, $count, $this->matchingCountItem($count, $entry), (float) $entry->quantity_in - (float) $entry->quantity_out);
            });
        }

        $adjustmentIds = $ledger
            ->filter(fn ($entry) => $entry->reference_type === StockAdjustmentVoucher::class && $entry->reference_id)
            ->pluck('reference_id')
            ->unique()
            ->values();

        if ($adjustmentIds->isEmpty()) {
            return;
        }

        $adjustments = StockAdjustmentVoucher::query()
            ->with('items')
            ->where('business_id', AppController::businessId())
            ->whereIn('id', $adjustmentIds)
            ->where('source', 'physical_count')
            ->get()
            ->keyBy('id');

        if ($adjustments->isEmpty()) {
            return;
        }

        $countNumbers = $adjustments
            ->map(fn ($adjustment) => $this->countNumberFromAdjustment($adjustment))
            ->filter()
            ->unique()
            ->values();

        $counts = StockCountSession::query()
            ->where('business_id', AppController::businessId())
            ->whereIn('session_number', $countNumbers)
            ->with('items')
            ->get()
            ->keyBy('session_number');

        $ledger->each(function (StockLedger $entry) use ($adjustments, $counts) {
            $adjustment = $adjustments->get((int) $entry->reference_id);
            if (!$adjustment) {
                return;
            }

            $countNumber = $this->countNumberFromAdjustment($adjustment);
            $count = $countNumber ? $counts->get($countNumber) : null;
            $variance = (float) $entry->quantity_in - (float) $entry->quantity_out;
            $item = $adjustment->items
                ->first(fn ($line) => (int) $line->product_id === (int) $entry->product_id
                    && (int) ($line->product_variant_id ?? 0) === (int) ($entry->product_variant_id ?? 0)
                    && (int) ($line->batch_id ?? 0) === (int) ($entry->batch_id ?? 0));
            $countItem = $count?->items
                ->first(fn ($line) => (int) $line->product_id === (int) $entry->product_id
                    && (int) ($line->product_variant_id ?? 0) === (int) ($entry->product_variant_id ?? 0)
                    && (int) ($line->batch_id ?? 0) === (int) ($entry->batch_id ?? 0));

            $this->applyCountLedgerMetadata($entry, $count, $countItem, $variance, $adjustment->voucher_number, $item?->reason);
        });
    }

    private function mapCountAdjustmentLedgerToSession(StockAdjustmentVoucher $voucher, StockCountSession $session): void
    {
        StockLedger::query()
            ->where('business_id', $voucher->business_id)
            ->where('reference_type', StockAdjustmentVoucher::class)
            ->where('reference_id', $voucher->id)
            ->get()
            ->each(function (StockLedger $entry) use ($session, $voucher) {
                $variance = (float) $entry->quantity_in - (float) $entry->quantity_out;
                $countItem = $this->matchingCountItem($session, $entry);
                $reason = $countItem?->reviewer_notes ?: ($variance < 0 ? 'Physical Count Shortage' : 'Physical Count Gain');

                $entry->update([
                    'reference_type' => StockCountSession::class,
                    'reference_id' => $session->id,
                    'transaction_type' => $variance < 0 ? 'physical_count_shortage' : 'physical_count_gain',
                    'remarks' => trim($session->session_number . ' | ' . $reason . ' | Linked adjustment ' . $voucher->voucher_number),
                ]);
            });
    }

    private function matchingCountItem(StockCountSession $count, StockLedger $entry)
    {
        return $count->items->first(fn ($line) => (int) $line->product_id === (int) $entry->product_id
            && (int) ($line->product_variant_id ?? 0) === (int) ($entry->product_variant_id ?? 0)
            && (int) ($line->batch_id ?? 0) === (int) ($entry->batch_id ?? 0)
        );
    }

    private function applyCountLedgerMetadata(StockLedger $entry, ?StockCountSession $count, $countItem, float $variance, ?string $linkedAdjustmentNumber = null, ?string $fallbackReason = null): void
    {
        $countNumber = $count?->session_number ?: $entry->reference_number;
        $entry->reference_number = $countNumber;
        $entry->source_type = 'stock_count';
        $entry->source_id = $count?->id;
        $entry->source_reference = $countNumber;
        $entry->source_document = 'Stock Count';
        $entry->source_number = $countNumber;
        $entry->linked_adjustment_number = $linkedAdjustmentNumber;
        $entry->transaction_type = $variance < 0 ? 'Physical Count Shortage' : 'Physical Count Gain';
        $entry->movement = 'Count Adjustment -> Saleable';
        $entry->physical_change = $variance;
        $entry->count_type = $count?->count_type;
        $entry->system_quantity = $countItem?->system_quantity;
        $entry->physical_quantity = $countItem?->counted_quantity;
        $entry->variance_quantity = $countItem?->variance_quantity ?? $variance;
        $entry->variance_reason = $countItem?->reviewer_notes ?: $fallbackReason ?: ($variance < 0 ? 'Physical Count Shortage' : 'Physical Count Gain');
        $entry->posted_by_name = $entry->creator?->name ?: ($count?->approved_by ? 'User' : 'System');
        $entry->posted_at = optional($entry->posted_at ?: $entry->created_at)->toDateTimeString();
        $entry->remarks = trim(($entry->variance_reason ?: '') . ' ' . ($countNumber ?: ''));
    }

    private function applyLedgerRegisterSearch(Builder $query, string $search): void
    {
        $term = '%' . trim($search) . '%';

        $query->where(function (Builder $q) use ($term) {
            $q->where('transaction_type', 'like', $term)
                ->orWhere('remarks', 'like', $term)
                ->orWhereHas('product', function (Builder $product) use ($term) {
                    $product->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhere('barcode', 'like', $term)
                        ->orWhere('primary_barcode', 'like', $term);
                })
                ->orWhereExists(function ($sub) use ($term) {
                    $sub->selectRaw('1')
                        ->from('stock_adjustment_vouchers')
                        ->whereColumn('stock_adjustment_vouchers.id', 'stock_ledgers.reference_id')
                        ->where('stock_ledgers.reference_type', StockAdjustmentVoucher::class)
                        ->where(function ($adjustment) use ($term) {
                            $adjustment->where('voucher_number', 'like', $term)
                                ->orWhere('remarks', 'like', $term)
                                ->orWhere('source', 'like', $term);
                        });
                })
                ->orWhereExists(function ($sub) use ($term) {
                    $sub->selectRaw('1')
                        ->from('stock_count_sessions')
                        ->whereColumn('stock_count_sessions.id', 'stock_ledgers.reference_id')
                        ->where('stock_ledgers.reference_type', StockCountSession::class)
                        ->where(function ($count) use ($term) {
                            $count->where('session_number', 'like', $term)
                                ->orWhere('remarks', 'like', $term)
                                ->orWhere('count_type', 'like', $term);
                        });
                })
                ->orWhereExists(function ($sub) use ($term) {
                    $sub->selectRaw('1')
                        ->from('stock_transfer_vouchers')
                        ->whereColumn('stock_transfer_vouchers.id', 'stock_ledgers.reference_id')
                        ->where('stock_ledgers.reference_type', StockTransferVoucher::class)
                        ->where(function ($transfer) use ($term) {
                            $transfer->where('voucher_number', 'like', $term)
                                ->orWhere('remarks', 'like', $term)
                                ->orWhere('transfer_type', 'like', $term);
                        });
                });
        });
    }

    private function applyLedgerRegisterType(Builder $query, string $type): void
    {
        $normalized = str($type)->lower()->replace(['-', '_'], ' ')->toString();

        if (str_contains($normalized, 'physical count')) {
            $query->where(function (Builder $q) {
                $q->where('reference_type', StockCountSession::class)
                    ->orWhereExists(function ($sub) {
                        $sub->selectRaw('1')
                            ->from('stock_adjustment_vouchers')
                            ->whereColumn('stock_adjustment_vouchers.id', 'stock_ledgers.reference_id')
                            ->where('stock_ledgers.reference_type', StockAdjustmentVoucher::class)
                            ->where('stock_adjustment_vouchers.source', 'physical_count');
                    });
            });

            if (str_contains($normalized, 'shortage')) {
                $query->whereColumn('quantity_out', '>', 'quantity_in');
            } elseif (str_contains($normalized, 'gain')) {
                $query->whereColumn('quantity_in', '>', 'quantity_out');
            }

            return;
        }

        if (str_contains($normalized, 'transfer')) {
            $query->where('reference_type', StockTransferVoucher::class);
            return;
        }

        $query->where(function (Builder $q) use ($type) {
            $q->where('transaction_type', 'like', '%' . $type . '%')
                ->orWhereExists(function ($sub) use ($type) {
                    $sub->selectRaw('1')
                        ->from('stock_adjustment_vouchers')
                        ->whereColumn('stock_adjustment_vouchers.id', 'stock_ledgers.reference_id')
                        ->where('stock_ledgers.reference_type', StockAdjustmentVoucher::class)
                        ->where('stock_adjustment_vouchers.source', 'like', '%' . $type . '%');
                });
        });
    }

    private function countNumberFromAdjustment(StockAdjustmentVoucher $adjustment): ?string
    {
        if (preg_match('/CNT-\d{4}-\d+/i', (string) $adjustment->remarks, $matches)) {
            return strtoupper($matches[0]);
        }

        return null;
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

    private function assertBatch(int $id, int $productId): ProductBatch
    {
        return ProductBatch::query()
            ->when(Schema::hasColumn('product_batches', 'business_id'), fn (Builder $q) => $q->where('business_id', AppController::businessId()))
            ->when(Schema::hasColumn('product_batches', 'tenant_id'), fn (Builder $q) => $q->where('tenant_id', AppController::businessId()))
            ->where('product_id', $productId)
            ->findOrFail($id);
    }

    private function assertSerial(int $id, int $productId): ProductSerialNumber
    {
        return ProductSerialNumber::query()->where('business_id', AppController::businessId())->where('product_id', $productId)->findOrFail($id);
    }

    private function assertAccount(int $id): Account
    {
        return Account::query()->where('business_id', AppController::businessId())->findOrFail($id);
    }

    private function columns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
    }

    private function productReferenceColumns(): array
    {
        return $this->columns('products', [
            'id',
            'name',
            'sku',
            'primary_barcode',
            'barcode',
            'unit_id',
            'unit',
            'batch_tracking',
            'serial_tracking',
            'batch_required',
            'serial_required',
        ]);
    }

    private function nextNumber(string $prefix, string $model, string $column): string
    {
        $businessId = AppController::businessId();
        $prefix .= '-' . date('Y') . '-';
        $last = $model::query()->where('business_id', $businessId)->where($column, 'like', $prefix . '%')->lockForUpdate()->orderByDesc('id')->value($column);
        return $prefix . str_pad((string) ($last ? ((int) substr($last, strlen($prefix)) + 1) : 1), 5, '0', STR_PAD_LEFT);
    }
}
