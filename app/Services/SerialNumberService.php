<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerialNumber;
use App\Models\SerialNumberHistory;
use App\Models\StockLedger;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SerialNumberService
{
    private const STATUSES = ['in_stock', 'reserved', 'sold', 'returned', 'damaged', 'under_repair', 'lost', 'transferred', 'blocked'];

    private const TRANSITIONS = [
        'in_stock' => ['reserved', 'sold', 'damaged', 'under_repair', 'lost', 'blocked', 'transferred'],
        'reserved' => ['in_stock', 'sold', 'blocked'],
        'sold' => ['returned'],
        'returned' => ['in_stock', 'damaged', 'under_repair', 'blocked'],
        'damaged' => ['under_repair', 'blocked'],
        'under_repair' => ['in_stock', 'damaged', 'blocked'],
        'lost' => [],
        'transferred' => ['in_stock', 'reserved', 'sold', 'damaged', 'blocked'],
        'blocked' => ['in_stock', 'under_repair', 'damaged'],
    ];

    public static function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/[\s\-]+/', '', trim((string) $value)));
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        $masters = app(MasterDataService::class)->references(['branches', 'warehouses', 'units']);

        return $masters + [
            'products' => Product::query()
                ->where(fn (Builder $q) => $this->scopeProduct($q, $businessId))
                ->where(function (Builder $q) {
                    $q->where('serial_required', true)->orWhereIn('tracking_type', ['serial', 'batch_serial']);
                })
                ->orderBy('name')
                ->limit(300)
                ->get($this->columns('products', ['id', 'name', 'sku', 'primary_barcode', 'barcode', 'batch_required', 'serial_required', 'tracking_type'])),
            'batches' => Schema::hasTable('product_batches') ? ProductBatch::query()->where('business_id', $businessId)->orderByDesc('id')->limit(300)->get($this->columns('product_batches', ['id', 'product_id', 'batch_no', 'batch_number', 'expiry_date', 'status'])) : [],
            'statuses' => self::STATUSES,
            'conditions' => ['new', 'good', 'fair', 'damaged', 'defective', 'refurbished'],
        ];
    }

    public function dashboard(array $filters = []): array
    {
        $rows = $this->baseQuery($filters)->get();
        $expiring = now()->addDays(30)->toDateString();

        return [
            'total_serials' => $rows->count(),
            'in_stock' => $rows->where('current_status', 'in_stock')->count(),
            'reserved' => $rows->where('current_status', 'reserved')->count(),
            'sold' => $rows->where('current_status', 'sold')->count(),
            'damaged' => $rows->where('current_status', 'damaged')->count(),
            'under_repair' => $rows->where('current_status', 'under_repair')->count(),
            'blocked' => $rows->where('current_status', 'blocked')->count(),
            'warranty_expiring' => $rows->filter(fn ($row) => $row->warranty_expiry_date && $row->warranty_expiry_date >= now()->toDateString() && $row->warranty_expiry_date <= $expiring)->count(),
        ];
    }

    public function list(array $filters = [])
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);
        return $this->baseQuery($filters)->latest('product_serial_numbers.id')->paginate($perPage);
    }

    public function detail(int $id): array
    {
        $serial = $this->serialQuery()->with(['product', 'variant', 'batch', 'branch', 'warehouse', 'customer', 'creator'])->findOrFail($id);
        $ledger = StockLedger::query()->with(['branch', 'warehouse', 'batch', 'creator'])->where('business_id', AppController::businessId())->where(function (Builder $q) use ($id) {
            $q->where('serial_id', $id)->orWhere('serial_number_id', $id);
        })->orderBy('transaction_date')->get();
        $history = SerialNumberHistory::query()->with(['branch', 'warehouse', 'creator'])->where('business_id', AppController::businessId())->where('serial_number_id', $id)->latest('id')->get();

        return [
            'serial' => $this->present($serial),
            'ledger' => $ledger->map(fn (StockLedger $row) => [
                'id' => $row->id,
                'date' => optional($row->transaction_date)->format('Y-m-d H:i'),
                'type' => $row->transaction_type,
                'reference' => class_basename($row->reference_type) . '#' . $row->reference_id,
                'branch' => optional($row->branch)->name,
                'warehouse' => optional($row->warehouse)->name,
                'batch' => optional($row->batch)->batch_no ?: optional($row->batch)->batch_number,
                'in' => (float) $row->quantity_in,
                'out' => (float) $row->quantity_out,
                'remarks' => $row->remarks,
                'user' => optional($row->creator)->name,
            ])->values(),
            'history' => $history->map(fn (SerialNumberHistory $row) => [
                'id' => $row->id,
                'date' => optional($row->created_at)->format('Y-m-d H:i'),
                'event_type' => $row->event_type,
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'branch' => optional($row->branch)->name,
                'warehouse' => optional($row->warehouse)->name,
                'remarks' => $row->remarks,
                'user' => optional($row->creator)->name,
            ])->values(),
        ];
    }

    public function store(array $data): ProductSerialNumber
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $product = $this->assertProduct((int) $data['product_id']);
            $this->assertSerialTracked($product);
            $this->assertLocation($businessId, $data);
            $normalized = self::normalize($data['serial_number']);
            $this->assertUnique($businessId, (int) $product->id, $normalized);

            $serial = ProductSerialNumber::query()->create([
                'business_id' => $businessId,
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'product_id' => $product->id,
                'product_variant_id' => $data['product_variant_id'] ?? null,
                'batch_id' => $data['batch_id'] ?? null,
                'serial_number' => trim($data['serial_number']),
                'normalized_serial_number' => $normalized,
                'secondary_serial_number' => $data['secondary_serial_number'] ?? null,
                'imei_1' => $data['imei_1'] ?? null,
                'imei_2' => $data['imei_2'] ?? null,
                'purchase_reference' => $data['purchase_reference'] ?? null,
                'sale_reference' => $data['sale_reference'] ?? null,
                'status' => $data['current_status'] ?? 'in_stock',
                'current_status' => $data['current_status'] ?? 'in_stock',
                'condition' => $data['condition'] ?? 'new',
                'purchase_date' => $data['purchase_date'] ?? null,
                'warranty_expiry_date' => $data['warranty_expiry_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $this->history($serial, 'created', null, $serial->current_status, $data['remarks'] ?? null);
            return $serial->fresh(['product', 'batch', 'branch', 'warehouse']);
        });
    }

    public function bulkStore(array $data): array
    {
        $created = [];
        foreach ($data['serials'] as $line) {
            $created[] = $this->store(array_merge($data, is_array($line) ? $line : ['serial_number' => $line]));
        }

        return $created;
    }

    public function update(int $id, array $data): ProductSerialNumber
    {
        return DB::transaction(function () use ($id, $data) {
            $serial = $this->serialQuery()->lockForUpdate()->findOrFail($id);
            $payload = collect($data)->only(['secondary_serial_number', 'imei_1', 'imei_2', 'purchase_reference', 'sale_reference', 'condition', 'purchase_date', 'warranty_expiry_date', 'remarks'])->all();
            $payload['updated_by'] = Auth::id();
            $serial->update($payload);
            $this->history($serial, 'updated', $serial->current_status, $serial->current_status, 'Metadata updated');
            return $serial->fresh(['product', 'batch', 'branch', 'warehouse']);
        });
    }

    public function transition(int $id, string $status, ?string $remarks = null): ProductSerialNumber
    {
        return DB::transaction(function () use ($id, $status, $remarks) {
            if (!in_array($status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['current_status' => 'Invalid serial status.']);
            }
            $serial = $this->serialQuery()->lockForUpdate()->findOrFail($id);
            $from = $serial->current_status ?: $serial->status ?: 'in_stock';
            if (!in_array($status, self::TRANSITIONS[$from] ?? [], true) && $status !== $from) {
                throw ValidationException::withMessages(['current_status' => "Cannot change serial from {$from} to {$status}."]);
            }
            $serial->update(['current_status' => $status, 'status' => $status, 'updated_by' => Auth::id(), 'sold_at' => $status === 'sold' ? now() : $serial->sold_at]);
            $this->history($serial, 'status_changed', $from, $status, $remarks);
            return $serial->fresh(['product', 'batch', 'branch', 'warehouse']);
        });
    }

    public function transfer(int $id, array $data, StockService $stock): ProductSerialNumber
    {
        return DB::transaction(function () use ($id, $data, $stock) {
            $serial = $this->serialQuery()->lockForUpdate()->findOrFail($id);
            if (!in_array($serial->current_status, ['in_stock', 'transferred'], true)) {
                throw ValidationException::withMessages(['serial_id' => 'Only in-stock serials can be transferred.']);
            }
            $fromBranch = $serial->branch_id;
            $fromWarehouse = $serial->warehouse_id;
            $toBranch = $data['destination_branch_id'] ?? null;
            $toWarehouse = $data['destination_warehouse_id'] ?? null;
            if ((int) $fromBranch === (int) $toBranch && (int) $fromWarehouse === (int) $toWarehouse) {
                throw ValidationException::withMessages(['destination_warehouse_id' => 'Destination must differ from current location.']);
            }
            $this->assertLocation(AppController::businessId(), ['branch_id' => $toBranch, 'warehouse_id' => $toWarehouse]);
            $scope = ['business_id' => AppController::businessId(), 'branch_id' => $fromBranch, 'warehouse_id' => $fromWarehouse, 'product_id' => $serial->product_id, 'product_variant_id' => $serial->product_variant_id, 'batch_id' => $serial->batch_id];
            $cost = $stock->getAverageCost($scope);
            $base = ['business_id' => AppController::businessId(), 'product_id' => $serial->product_id, 'product_variant_id' => $serial->product_variant_id, 'batch_id' => $serial->batch_id, 'serial_id' => $serial->id, 'quantity' => 1, 'unit_cost' => $cost, 'reference_type' => ProductSerialNumber::class, 'reference_id' => $serial->id, 'transaction_date' => now(), 'remarks' => $data['remarks'] ?? 'Serial transfer'];
            $stock->decreaseStock($base + ['branch_id' => $fromBranch, 'warehouse_id' => $fromWarehouse, 'transaction_type' => 'stock_transfer_out']);
            $stock->increaseStock($base + ['branch_id' => $toBranch, 'warehouse_id' => $toWarehouse, 'transaction_type' => 'stock_transfer_in']);
            $from = $serial->current_status;
            $serial->update(['branch_id' => $toBranch, 'warehouse_id' => $toWarehouse, 'current_status' => 'transferred', 'status' => 'transferred', 'updated_by' => Auth::id()]);
            $this->history($serial, 'transferred', $from, 'transferred', $data['remarks'] ?? null);
            return $serial->fresh(['product', 'batch', 'branch', 'warehouse']);
        });
    }

    public function destroy(int $id): void
    {
        $serial = $this->serialQuery()->findOrFail($id);
        $used = StockLedger::query()->where('business_id', AppController::businessId())->where(fn (Builder $q) => $q->where('serial_id', $id)->orWhere('serial_number_id', $id))->exists();
        if ($used) {
            throw ValidationException::withMessages(['serial_id' => 'Serial used in posted transactions cannot be deleted.']);
        }
        $serial->delete();
    }

    public function reports(array $filters = []): array
    {
        $rows = $this->baseQuery($filters)->get();
        return [
            'serial_stock' => $rows->whereIn('current_status', ['in_stock', 'reserved'])->values(),
            'sold_serials' => $rows->where('current_status', 'sold')->values(),
            'warranty_expiry' => $rows->whereNotNull('warranty_expiry_date')->values(),
            'damaged_blocked' => $rows->whereIn('current_status', ['damaged', 'blocked', 'under_repair'])->values(),
            'serial_movement' => SerialNumberHistory::query()->with(['serial.product', 'branch', 'warehouse', 'creator'])->where('business_id', AppController::businessId())->latest('id')->limit(500)->get(),
        ];
    }

    private function baseQuery(array $filters = [])
    {
        return $this->serialQuery()->with(['product', 'variant', 'batch', 'branch', 'warehouse', 'customer'])
            ->when(!empty($filters['search']), function (Builder $q) use ($filters) {
                $s = '%' . $filters['search'] . '%';
                $q->where(function (Builder $query) use ($s) {
                    $query->where('serial_number', 'like', $s)->orWhere('imei_1', 'like', $s)->orWhere('imei_2', 'like', $s)->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', $s)->orWhere('sku', 'like', $s)->orWhere('barcode', 'like', $s)->orWhere('primary_barcode', 'like', $s));
                });
            })
            ->when(!empty($filters['product_id']), fn (Builder $q) => $q->where('product_id', $filters['product_id']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(!empty($filters['warehouse_id']), fn (Builder $q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(!empty($filters['batch_id']), fn (Builder $q) => $q->where('batch_id', $filters['batch_id']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('current_status', $filters['status']))
            ->when(!empty($filters['condition']), fn (Builder $q) => $q->where('condition', $filters['condition']))
            ->when(($filters['warranty_filter'] ?? '') === 'expiring', fn (Builder $q) => $q->whereBetween('warranty_expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()]))
            ->when(($filters['warranty_filter'] ?? '') === 'expired', fn (Builder $q) => $q->whereDate('warranty_expiry_date', '<', now()->toDateString()))
            ->when(!empty($filters['date_from']), fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['date_to']));
    }

    private function serialQuery(): Builder
    {
        return ProductSerialNumber::query()->where('business_id', AppController::businessId());
    }

    private function assertProduct(int $id): Product
    {
        return Product::query()->where('id', $id)->where(fn (Builder $q) => $this->scopeProduct($q, AppController::businessId()))->firstOrFail();
    }

    private function assertSerialTracked(Product $product): void
    {
        $tracked = (bool) ($product->serial_required ?? false) || in_array($product->tracking_type, ['serial', 'batch_serial'], true);
        $service = in_array($product->product_type ?? $product->item_type ?? 'goods', ['service', 'services'], true);
        if (!$tracked || $service) {
            throw ValidationException::withMessages(['product_id' => 'Selected product does not require serial tracking.']);
        }
    }

    private function assertUnique(int $businessId, int $productId, string $normalized): void
    {
        $exists = ProductSerialNumber::withTrashed()->where('business_id', $businessId)->where('product_id', $productId)->where('normalized_serial_number', $normalized)->exists();
        if ($exists) {
            throw ValidationException::withMessages(['serial_number' => 'Serial number already exists for this product.']);
        }
    }

    private function assertLocation(int $businessId, array $data): void
    {
        if (!empty($data['branch_id'])) Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        if (!empty($data['warehouse_id'])) Warehouse::query()->where('business_id', $businessId)->where('id', $data['warehouse_id'])->firstOrFail();
    }

    private function history(ProductSerialNumber $serial, string $event, ?string $from, ?string $to, ?string $remarks = null): void
    {
        SerialNumberHistory::query()->create(['business_id' => AppController::businessId(), 'serial_number_id' => $serial->id, 'product_id' => $serial->product_id, 'branch_id' => $serial->branch_id, 'warehouse_id' => $serial->warehouse_id, 'event_type' => $event, 'from_status' => $from, 'to_status' => $to, 'remarks' => $remarks, 'created_by' => Auth::id()]);
    }

    private function present(ProductSerialNumber $serial): array
    {
        return [
            'id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'imei_1' => $serial->imei_1,
            'imei_2' => $serial->imei_2,
            'product_id' => $serial->product_id,
            'product' => optional($serial->product)->name,
            'sku' => optional($serial->product)->sku,
            'variant' => optional($serial->variant)->sku,
            'batch' => optional($serial->batch)->batch_no ?: optional($serial->batch)->batch_number,
            'branch' => optional($serial->branch)->name,
            'warehouse' => optional($serial->warehouse)->name,
            'condition' => $serial->condition,
            'warranty_expiry_date' => optional($serial->warranty_expiry_date)->format('Y-m-d'),
            'purchase_reference' => $serial->purchase_reference,
            'sale_reference' => $serial->sale_reference,
            'current_status' => $serial->current_status,
            'remarks' => $serial->remarks,
        ];
    }

    private function scopeProduct(Builder $query, int $businessId): void
    {
        if (Schema::hasColumn('products', 'business_id')) $query->where('business_id', $businessId);
        if (Schema::hasColumn('products', 'company_id')) $query->{Schema::hasColumn('products', 'business_id') ? 'orWhere' : 'where'}('company_id', $businessId);
    }

    private function columns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
    }
}
