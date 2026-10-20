<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\OpeningStockVoucher;
use App\Models\Product;
use App\Models\ProductSerialNumber;
use App\Models\SerialNumberHistory;
use App\Models\StockLedger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Drafts hold intent only. Posted identity and movements live in the serial register/ledger. */
class VoucherSerialService
{
    public static function tracked(Product $product): bool
    {
        return $product->serial_required || in_array($product->tracking_type, ['serial', 'batch_serial'], true);
    }

    public function lockBusiness(int $businessId): void
    {
        // Serialize register creation across products, including the manual register endpoint.
        DB::table('companies')->where('id', $businessId)->lockForUpdate()->first();
    }

    public function ledger(ProductSerialNumber $serial)
    {
        return StockLedger::query()->where('business_id', $serial->business_id)
            ->where(fn ($q) => $q->where('serial_id', $serial->id)->orWhere('serial_number_id', $serial->id));
    }

    public function openingReason(ProductSerialNumber $serial, array $scope): ?string
    {
        if ($serial->trashed()) return 'This serial has been deleted.';
        foreach (['business_id', 'product_id', 'branch_id', 'warehouse_id', 'product_variant_id', 'batch_id'] as $key) {
            if ((int) $serial->$key !== (int) ($scope[$key] ?? 0)) return 'Serial must belong to the same product, variant, batch, branch and warehouse.';
        }
        if ($serial->current_status !== 'in_stock' || $serial->status !== 'in_stock' || !in_array($serial->condition, ['new', 'good', 'fair', 'refurbished'], true)) {
            return 'Only in-stock, undamaged, unreserved serials can be assigned.';
        }
        if ($serial->purchase_reference || $serial->sale_reference || $serial->sold_at || $serial->customer_id || $this->ledger($serial)->exists()
            || SerialNumberHistory::where('serial_number_id', $serial->id)->where('business_id', $serial->business_id)->whereNotIn('event_type', ['created', 'updated'])->exists()) {
            return 'Serial already has a stock transaction or prior movement/purchase/sale linkage.';
        }
        return null;
    }

    public function candidates(array $scope, string $purpose): array
    {
        $scope['business_id'] = AppController::businessId();
        $query = ProductSerialNumber::query()->with(['branch', 'warehouse'])
            ->where('business_id', $scope['business_id'])->where('product_id', $scope['product_id'] ?? 0)
            ->where('branch_id', $scope['branch_id'] ?? null)->where('warehouse_id', $scope['warehouse_id'] ?? null)
            ->where('product_variant_id', $scope['product_variant_id'] ?? null)->where('batch_id', $scope['batch_id'] ?? null)
            ->whereIn('current_status', $purpose === 'opening' ? ['in_stock'] : ['in_stock', 'transferred'])
            ->when(!empty($scope['search']), fn ($q) => $q->where('serial_number', 'like', '%'.$scope['search'].'%'));
        return $query->orderBy('id')->get()->filter(fn ($serial) => $purpose === 'opening'
            ? !$this->openingReason($serial, $scope) : $this->saleAvailable($serial, $scope))
            ->take(100)->map(fn ($serial) => [
                'serial_number_id' => $serial->id, 'serial_number' => $serial->serial_number,
                'status' => $serial->current_status, 'branch' => $serial->branch?->name,
                'warehouse' => $serial->warehouse?->name,
            ])->values()->all();
    }

    public function assignments(array $rows, float $quantity, bool $posting, string $field): array
    {
        if ($quantity <= 0 || floor($quantity) !== $quantity) $this->fail($field, 'Serial-tracked quantity must be a positive integer.');
        if ($posting && count($rows) !== (int) $quantity) $this->fail($field, "Exactly {$quantity} unique serial numbers are required; ".count($rows).' assigned.');
        $seen = [];
        foreach ($rows as &$row) {
            $row = ['serial_number_id' => $row['serial_number_id'] ?? null, 'serial_number' => trim($row['serial_number'] ?? '')];
            $key = SerialNumberService::normalize($row['serial_number']);
            if (!$key || strlen($row['serial_number']) > 120) $this->fail($field, 'Enter a valid serial number (maximum 120 characters).');
            if (isset($seen[$key])) $this->fail($field, 'Duplicate serial number: '.$row['serial_number']);
            $seen[$key] = true;
        }
        return $rows;
    }

    public function open($voucher, $item, string $field): void
    {
        $this->lockBusiness($voucher->business_id);
        $scope = $this->scope($voucher, $item);
        if (($item->condition_status ?: 'saleable') !== 'saleable') $this->fail($field, 'Serial opening stock currently supports saleable condition only.');
        $rows = $this->assignments($item->serials ?? [], (float) $item->quantity, true, $field);
        foreach ($rows as &$row) {
            $matches = ProductSerialNumber::withTrashed()->where('business_id', $voucher->business_id)
                ->where(fn ($q) => $q->where('normalized_serial_number', SerialNumberService::normalize($row['serial_number']))->orWhereNull('normalized_serial_number'))
                ->lockForUpdate()->get()->filter(fn ($serial) => SerialNumberService::normalize($serial->serial_number) === SerialNumberService::normalize($row['serial_number']));
            if ($row['serial_number_id']) {
                $serial = ProductSerialNumber::withTrashed()->where('business_id', $voucher->business_id)->lockForUpdate()->find($row['serial_number_id']);
                if (!$serial || SerialNumberService::normalize($serial->serial_number) !== SerialNumberService::normalize($row['serial_number'])) $this->fail($field, 'Selected serial ID does not match its number.');
                if ($matches->contains(fn ($match) => $match->id !== $serial->id)) $this->fail($field, 'Serial number also exists on another product.');
                if ($reason = $this->openingReason($serial, $scope)) $this->fail($field, $row['serial_number'].': '.$reason);
            } else {
                if ($matches->isNotEmpty()) $this->fail($field, $row['serial_number'].': serial already exists. Select an eligible existing serial instead.');
                $serial = app(SerialNumberService::class)->store($scope + ['serial_number' => $row['serial_number']]);
            }
            app(StockService::class)->increaseStock($scope + [
                'serial_id' => $serial->id, 'quantity' => 1, 'unit_cost' => $item->purchase_cost,
                'transaction_type' => 'opening_stock', 'reference_type' => OpeningStockVoucher::class,
                'reference_id' => $voucher->id, 'transaction_date' => $voucher->opening_date,
                'warehouse_location' => $item->warehouse_location, 'remarks' => 'Opening stock '.$voucher->voucher_number,
            ]);
            $serial->update(['purchase_reference' => $voucher->voucher_number, 'purchase_date' => $voucher->opening_date,
                'status' => 'in_stock', 'current_status' => 'in_stock', 'updated_by' => Auth::id()]);
            $this->history($serial, $voucher, 'opening_stock', 'in_stock', 'in_stock', $voucher->opening_date, 'Opening stock '.$voucher->voucher_number);
            $row['serial_number_id'] = $serial->id;
        }
        $item->update(['serials' => $rows, 'serial_number_id' => null]);
    }

    public function saleAvailable(ProductSerialNumber $serial, array $scope): bool
    {
        foreach (['business_id', 'product_id', 'branch_id', 'warehouse_id', 'product_variant_id', 'batch_id'] as $key) {
            if ((int) $serial->$key !== (int) ($scope[$key] ?? 0)) return false;
        }
        if ($serial->trashed() || !in_array($serial->current_status, ['in_stock', 'transferred'], true)
            || !in_array($serial->status, ['in_stock', 'transferred'], true)
            || !in_array($serial->condition, ['new', 'good', 'fair', 'refurbished'], true)) return false;
        $ledger = $this->ledger($serial)->where('branch_id', $scope['branch_id'])->where('warehouse_id', $scope['warehouse_id'])
            ->where('stock_status', 'saleable');
        // Locking reads avoid stale snapshots when another sale just committed.
        return abs((float) $ledger->lockForUpdate()->get(['quantity_in', 'quantity_out'])->sum(fn ($r) => $r->quantity_in - $r->quantity_out) - 1) < 0.0001;
    }

    public function sell($voucher, $item, string $field): void
    {
        $scope = $this->scope($voucher, $item);
        $rows = $this->assignments($item->serials ?? [], (float) $item->quantity + (float) $item->free_quantity, true, $field);
        foreach ($rows as $row) {
            $serial = ProductSerialNumber::where('business_id', $voucher->business_id)->lockForUpdate()->find($row['serial_number_id']);
            if (!$serial || SerialNumberService::normalize($serial->serial_number) !== SerialNumberService::normalize($row['serial_number']) || !$this->saleAvailable($serial, $scope)) {
                $this->fail($field, $row['serial_number'].': select an available serial with posted stock in this product and location.');
            }
            app(StockService::class)->decreaseStock($scope + ['serial_id' => $serial->id, 'quantity' => 1,
                'unit_cost' => $item->cost_rate, 'transaction_type' => 'sale', 'reference_type' => get_class($voucher),
                'reference_id' => $voucher->id, 'transaction_date' => $voucher->invoice_date, 'remarks' => 'Sale '.$voucher->invoice_number]);
            $from = $serial->current_status;
            $serial->update(['status' => 'sold', 'current_status' => 'sold', 'sale_reference' => $voucher->invoice_number,
                'sold_at' => now(), 'customer_id' => $voucher->customer_id, 'updated_by' => Auth::id()]);
            $this->history($serial, $voucher, 'sale', $from, 'sold', $voucher->invoice_date, 'Sale '.$voucher->invoice_number);
        }
    }

    public function reverseOpening($voucher): void
    {
        foreach ($voucher->items as $item) {
            foreach ($item->serials ?? [] as $row) {
                $serial = ProductSerialNumber::where('business_id', $voucher->business_id)->lockForUpdate()->find($row['serial_number_id']);
                if (!$serial || !$this->saleAvailable($serial, $this->scope($voucher, $item)) || $serial->sale_reference
                    || $this->ledger($serial)->where(fn ($q) => $q->where('reference_type', '!=', OpeningStockVoucher::class)->orWhere('reference_id', '!=', $voucher->id))->lockForUpdate()->get()->isNotEmpty()
                    || SerialNumberHistory::where('serial_number_id', $serial->id)->whereNotIn('event_type', ['created', 'updated', 'opening_stock'])->lockForUpdate()->get()->isNotEmpty()) {
                    $this->fail('serials', 'Cannot reverse: an assigned serial was sold, moved or changed downstream.');
                }
                $serial->update(['status' => 'blocked', 'current_status' => 'blocked', 'updated_by' => Auth::id()]);
                $this->history($serial, $voucher, 'opening_stock_reversal', 'in_stock', 'blocked', now(), 'Reversal of '.$voucher->voucher_number);
            }
        }
    }

    private function scope($voucher, $item): array
    {
        return ['business_id' => $voucher->business_id, 'branch_id' => $voucher->branch_id, 'warehouse_id' => $voucher->warehouse_id,
            'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'batch_id' => $item->batch_id];
    }

    private function history($serial, $voucher, string $event, string $from, string $to, $date, string $remarks): void
    {
        SerialNumberHistory::create(['business_id' => $serial->business_id, 'serial_number_id' => $serial->id,
            'product_id' => $serial->product_id, 'branch_id' => $serial->branch_id, 'warehouse_id' => $serial->warehouse_id,
            'event_type' => $event, 'from_status' => $from, 'to_status' => $to, 'voucher_type' => get_class($voucher),
            'voucher_id' => $voucher->id, 'occurred_at' => $date, 'remarks' => $remarks, 'created_by' => Auth::id()]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
