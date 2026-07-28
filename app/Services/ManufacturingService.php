<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductionWastage;
use App\Models\StockLedger;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ManufacturingService
{
    private StockService $stock;

    public function __construct(StockService $stock)
    {
        $this->stock = $stock;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        return app(MasterDataService::class)->references(['branches', 'warehouses', 'units', 'categories', 'brands']) + [
            'products' => Product::query()->where(fn (Builder $q) => $this->scopeProduct($q, $businessId))->where('status', 'active')->orderBy('name')->limit(500)->get($this->columns('products', ['id', 'name', 'sku', 'unit_id', 'batch_required', 'serial_required', 'tracking_type', 'default_selling_price', 'selling_price'])),
            'boms' => Bom::query()->with('finishedProduct')->where('business_id', $businessId)->where('status', 'active')->latest('id')->limit(200)->get(),
            'statuses' => ['draft', 'active', 'inactive', 'planned', 'material_reserved', 'in_progress', 'completed', 'cancelled'],
        ];
    }

    public function dashboard(array $filters = []): array
    {
        $businessId = AppController::businessId();
        return [
            'bom' => [
                'total_boms' => Bom::query()->where('business_id', $businessId)->count(),
                'active_boms' => Bom::query()->where('business_id', $businessId)->where('status', 'active')->count(),
                'draft_boms' => Bom::query()->where('business_id', $businessId)->where('status', 'draft')->count(),
                'finished_products' => Bom::query()->where('business_id', $businessId)->distinct('finished_product_id')->count('finished_product_id'),
                'raw_materials_used' => BomItem::query()->whereIn('bom_id', Bom::query()->where('business_id', $businessId)->select('id'))->distinct('raw_material_product_id')->count('raw_material_product_id'),
            ],
            'production' => [
                'planned_orders' => ProductionOrder::query()->where('business_id', $businessId)->where('status', 'planned')->count(),
                'in_progress' => ProductionOrder::query()->where('business_id', $businessId)->where('status', 'in_progress')->count(),
                'completed_today' => ProductionOrder::query()->where('business_id', $businessId)->where('status', 'completed')->whereDate('actual_completion_date', today())->count(),
                'material_shortage' => ProductionOrderItem::query()->whereIn('production_order_id', ProductionOrder::query()->where('business_id', $businessId)->select('id'))->where('availability_status', 'shortage')->count(),
                'produced_quantity' => (float) ProductionOrder::query()->where('business_id', $businessId)->sum('produced_quantity'),
                'production_value' => (float) ProductionOrder::query()->where('business_id', $businessId)->sum('production_cost'),
            ],
        ];
    }

    public function boms(array $filters = [])
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);
        return Bom::query()->with(['finishedProduct', 'finishedVariant', 'unit', 'items.rawMaterial'])
            ->where('business_id', AppController::businessId())
            ->when(!empty($filters['search']), fn (Builder $q) => $q->where(fn (Builder $x) => $x->where('bom_code', 'like', '%' . $filters['search'] . '%')->orWhere('bom_name', 'like', '%' . $filters['search'] . '%')->orWhereHas('finishedProduct', fn (Builder $p) => $p->where('name', 'like', '%' . $filters['search'] . '%')->orWhere('sku', 'like', '%' . $filters['search'] . '%'))))
            ->when(!empty($filters['product_id']), fn (Builder $q) => $q->where('finished_product_id', $filters['product_id']))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function saveBom(array $data, ?int $id = null): Bom
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $bom = $id ? Bom::query()->where('business_id', $businessId)->with('items')->findOrFail($id) : new Bom(['business_id' => $businessId, 'created_by' => Auth::id()]);
            if ($bom->exists && $bom->status === 'active') {
                throw ValidationException::withMessages(['status' => 'Active BOMs must be duplicated as a new version before editing.']);
            }
            $this->assertProduct((int) $data['finished_product_id']);
            $this->validateBomItems((int) $data['finished_product_id'], $data['items'] ?? [], $id);
            $bom->fill([
                'finished_product_id' => $data['finished_product_id'],
                'finished_product_variant_id' => $data['finished_product_variant_id'] ?? null,
                'bom_code' => $data['bom_code'] ?? $this->nextBomCode(),
                'bom_name' => $data['bom_name'],
                'version' => $data['version'] ?? ($bom->version ?: 1),
                'output_quantity' => $data['output_quantity'] ?? 1,
                'unit_id' => $data['unit_id'] ?? null,
                'wastage_percentage' => $data['wastage_percentage'] ?? 0,
                'status' => $data['status'] ?? 'draft',
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => Auth::id(),
            ])->save();
            $bom->items()->delete();
            foreach ($data['items'] ?? [] as $line) {
                $bom->items()->create([
                    'raw_material_product_id' => $line['raw_material_product_id'],
                    'raw_material_variant_id' => $line['raw_material_variant_id'] ?? null,
                    'quantity_required' => $line['quantity_required'],
                    'unit_id' => $line['unit_id'] ?? null,
                    'wastage_percentage' => $line['wastage_percentage'] ?? 0,
                    'warehouse_id' => $line['warehouse_id'] ?? null,
                    'batch_selection_method' => $line['batch_selection_method'] ?? 'fefo',
                    'remarks' => $line['remarks'] ?? null,
                ]);
            }
            return $bom->fresh(['finishedProduct', 'items.rawMaterial']);
        });
    }

    public function duplicateBom(int $id): Bom
    {
        return DB::transaction(function () use ($id) {
            $source = Bom::query()->with('items')->where('business_id', AppController::businessId())->findOrFail($id);
            $copy = $source->replicate(['status', 'approved_by', 'approved_at']);
            $copy->bom_code = $this->nextBomCode();
            $copy->bom_name = $source->bom_name . ' v' . ((int) $source->version + 1);
            $copy->version = (int) $source->version + 1;
            $copy->status = 'draft';
            $copy->created_by = Auth::id();
            $copy->approved_by = null;
            $copy->approved_at = null;
            $copy->save();
            foreach ($source->items as $item) {
                $copy->items()->create($item->only(['raw_material_product_id', 'raw_material_variant_id', 'quantity_required', 'unit_id', 'wastage_percentage', 'warehouse_id', 'batch_selection_method', 'remarks']));
            }
            return $copy->fresh(['finishedProduct', 'items.rawMaterial']);
        });
    }

    public function activateBom(int $id, bool $active = true): Bom
    {
        return DB::transaction(function () use ($id, $active) {
            $bom = Bom::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($id);
            if ($active && !$bom->items()->exists()) {
                throw ValidationException::withMessages(['items' => 'Add at least one raw material before activating BOM.']);
            }
            if ($active) {
                Bom::query()->where('business_id', AppController::businessId())->where('finished_product_id', $bom->finished_product_id)->where('id', '<>', $bom->id)->where('status', 'active')->update(['status' => 'inactive']);
            }
            $bom->update(['status' => $active ? 'active' : 'inactive', 'approved_by' => $active ? Auth::id() : $bom->approved_by, 'approved_at' => $active ? now() : $bom->approved_at]);
            return $bom->fresh(['finishedProduct', 'items.rawMaterial']);
        });
    }

    public function orders(array $filters = [])
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);
        return ProductionOrder::query()->with(['bom', 'finishedProduct', 'branch', 'sourceWarehouse', 'finishedWarehouse', 'items.rawMaterial'])
            ->where('business_id', AppController::businessId())
            ->when(!empty($filters['search']), fn (Builder $q) => $q->where(fn (Builder $x) => $x->where('order_number', 'like', '%' . $filters['search'] . '%')->orWhereHas('finishedProduct', fn (Builder $p) => $p->where('name', 'like', '%' . $filters['search'] . '%')->orWhere('sku', 'like', '%' . $filters['search'] . '%'))))
            ->when(!empty($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->latest('id')
            ->paginate($perPage);
    }

    public function saveOrder(array $data, ?int $id = null): ProductionOrder
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $order = $id ? ProductionOrder::query()->where('business_id', $businessId)->with('items')->findOrFail($id) : new ProductionOrder(['business_id' => $businessId, 'order_number' => $this->nextOrderNumber(), 'created_by' => Auth::id()]);
            if ($order->exists && $order->status === 'completed') {
                throw ValidationException::withMessages(['status' => 'Completed production orders cannot be edited.']);
            }
            $bom = Bom::query()->where('business_id', $businessId)->with('items')->findOrFail($data['bom_id']);
            if ($bom->status !== 'active') {
                throw ValidationException::withMessages(['bom_id' => 'Only active BOMs can be used for production.']);
            }
            $this->assertLocation($businessId, $data);
            $planned = (float) $data['planned_quantity'];
            $factor = $planned / max((float) $bom->output_quantity, 0.001);
            $order->fill([
                'bom_id' => $bom->id,
                'bom_version' => $bom->version,
                'finished_product_id' => $bom->finished_product_id,
                'finished_product_variant_id' => $bom->finished_product_variant_id,
                'branch_id' => $data['branch_id'] ?? null,
                'source_warehouse_id' => $data['source_warehouse_id'],
                'finished_goods_warehouse_id' => $data['finished_goods_warehouse_id'],
                'planned_quantity' => $planned,
                'produced_quantity' => $data['produced_quantity'] ?? 0,
                'rejected_quantity' => $data['rejected_quantity'] ?? 0,
                'start_date' => $data['start_date'] ?? null,
                'expected_completion_date' => $data['expected_completion_date'] ?? null,
                'status' => $data['status'] ?? $order->status ?: 'draft',
                'assigned_user_id' => $data['assigned_user_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'additional_cost' => $data['additional_cost'] ?? 0,
                'manufacturing_date' => $data['manufacturing_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
            ])->save();
            $order->items()->delete();
            foreach ($bom->items as $item) {
                $required = round(((float) $item->quantity_required * $factor) * (1 + ((float) $item->wastage_percentage / 100)), 3);
                $scope = ['business_id' => $businessId, 'branch_id' => $order->branch_id, 'warehouse_id' => $item->warehouse_id ?: $order->source_warehouse_id, 'product_id' => $item->raw_material_product_id, 'product_variant_id' => $item->raw_material_variant_id];
                $available = $this->stock->getCurrentStock($scope);
                $cost = $this->stock->getAverageCost($scope);
                $order->items()->create([
                    'raw_material_product_id' => $item->raw_material_product_id,
                    'raw_material_variant_id' => $item->raw_material_variant_id,
                    'required_quantity' => $required,
                    'consumed_quantity' => $required,
                    'unit_cost' => $cost,
                    'total_cost' => round($required * $cost, 2),
                    'availability_status' => $available >= $required ? 'available' : 'shortage',
                ]);
            }
            return $order->fresh(['bom', 'finishedProduct', 'branch', 'sourceWarehouse', 'finishedWarehouse', 'items.rawMaterial']);
        });
    }

    public function checkMaterials(int $id): array
    {
        $order = ProductionOrder::query()->with(['items.rawMaterial'])->where('business_id', AppController::businessId())->findOrFail($id);
        $rows = $order->items->map(function (ProductionOrderItem $item) use ($order) {
            $scope = ['business_id' => $order->business_id, 'branch_id' => $order->branch_id, 'warehouse_id' => $order->source_warehouse_id, 'product_id' => $item->raw_material_product_id, 'product_variant_id' => $item->raw_material_variant_id, 'batch_id' => $item->batch_id];
            $available = $this->stock->getCurrentStock($scope);
            $required = (float) $item->required_quantity;
            $item->update(['availability_status' => $available >= $required ? 'available' : 'shortage', 'unit_cost' => $this->stock->getAverageCost($scope), 'total_cost' => round($required * $this->stock->getAverageCost($scope), 2)]);
            return ['id' => $item->id, 'raw_material' => optional($item->rawMaterial)->name, 'required_quantity' => $required, 'available_quantity' => $available, 'shortage_quantity' => max(0, $required - $available), 'selected_batch' => $item->batch_id, 'unit_cost' => (float) $item->unit_cost, 'total_cost' => (float) $item->total_cost, 'availability_status' => $available >= $required ? 'available' : 'shortage'];
        })->values();
        return ['requirements' => $rows, 'has_shortage' => $rows->contains(fn ($row) => $row['availability_status'] === 'shortage')];
    }

    public function transitionOrder(int $id, string $status): ProductionOrder
    {
        return DB::transaction(function () use ($id, $status) {
            $order = ProductionOrder::query()->where('business_id', AppController::businessId())->findOrFail($id);
            $from = $order->status;
            $allowed = [
                'draft' => ['planned', 'cancelled'],
                'planned' => ['material_reserved', 'in_progress', 'cancelled'],
                'material_reserved' => ['in_progress', 'cancelled'],
                'in_progress' => ['completed', 'cancelled'],
            ];
            if (!in_array($status, $allowed[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "Cannot change production order from {$from} to {$status}."]);
            }
            if ($status === 'material_reserved' && $this->checkMaterials($id)['has_shortage']) {
                throw ValidationException::withMessages(['materials' => 'Material shortage prevents reservation.']);
            }
            $order->update(['status' => $status, 'approved_by' => in_array($status, ['planned', 'material_reserved'], true) ? Auth::id() : $order->approved_by]);
            return $order->fresh(['items.rawMaterial', 'finishedProduct']);
        });
    }

    public function completeOrder(int $id, array $data): ProductionOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $order = ProductionOrder::query()->where('business_id', AppController::businessId())->with(['items.rawMaterial', 'finishedProduct'])->lockForUpdate()->findOrFail($id);
            if (!in_array($order->status, ['planned', 'material_reserved', 'in_progress'], true)) {
                throw ValidationException::withMessages(['status' => 'Only planned, reserved or in-progress orders can be completed.']);
            }
            if ($this->checkMaterials($id)['has_shortage']) {
                throw ValidationException::withMessages(['materials' => 'Insufficient material prevents production posting.']);
            }
            $produced = (float) ($data['produced_quantity'] ?? $order->planned_quantity);
            if ($produced <= 0) {
                throw ValidationException::withMessages(['produced_quantity' => 'Produced quantity must be greater than zero.']);
            }
            $materialCost = 0.0;
            foreach ($order->items as $item) {
                $scope = ['business_id' => $order->business_id, 'branch_id' => $order->branch_id, 'warehouse_id' => $order->source_warehouse_id, 'product_id' => $item->raw_material_product_id, 'product_variant_id' => $item->raw_material_variant_id, 'batch_id' => $item->batch_id];
                $cost = $this->stock->getAverageCost($scope);
                $qty = (float) ($data['items'][$item->id]['consumed_quantity'] ?? $item->consumed_quantity ?: $item->required_quantity);
                $this->stock->decreaseStock($scope + ['quantity' => $qty, 'unit_cost' => $cost, 'transaction_type' => 'manufacturing_consumption', 'reference_type' => ProductionOrder::class, 'reference_id' => $order->id, 'remarks' => 'Production material consumption']);
                $materialCost += $qty * $cost;
                $waste = (float) ($data['items'][$item->id]['wastage_quantity'] ?? $item->wastage_quantity ?? 0);
                if ($waste > 0) {
                    ProductionWastage::query()->create(['business_id' => $order->business_id, 'production_order_id' => $order->id, 'product_id' => $item->raw_material_product_id, 'product_variant_id' => $item->raw_material_variant_id, 'batch_id' => $item->batch_id, 'quantity' => $waste, 'unit_cost' => $cost, 'total_cost' => round($waste * $cost, 2), 'reason' => $data['items'][$item->id]['wastage_reason'] ?? 'Production wastage']);
                }
                $item->update(['consumed_quantity' => $qty, 'wastage_quantity' => $waste, 'unit_cost' => $cost, 'total_cost' => round($qty * $cost, 2)]);
            }
            $finishedBatchId = $this->ensureFinishedBatch($order, $data);
            $totalCost = round($materialCost + (float) ($data['additional_cost'] ?? $order->additional_cost), 2);
            $unitCost = round($totalCost / max($produced, 0.001), 2);
            $this->stock->increaseStock(['business_id' => $order->business_id, 'branch_id' => $order->branch_id, 'warehouse_id' => $order->finished_goods_warehouse_id, 'product_id' => $order->finished_product_id, 'product_variant_id' => $order->finished_product_variant_id, 'batch_id' => $finishedBatchId, 'quantity' => $produced, 'unit_cost' => $unitCost, 'transaction_type' => 'manufacturing_output', 'reference_type' => ProductionOrder::class, 'reference_id' => $order->id, 'remarks' => 'Production finished goods']);
            $order->update(['status' => 'completed', 'produced_quantity' => $produced, 'rejected_quantity' => $data['rejected_quantity'] ?? 0, 'additional_cost' => $data['additional_cost'] ?? $order->additional_cost, 'production_cost' => $totalCost, 'cost_per_unit' => $unitCost, 'finished_batch_id' => $finishedBatchId, 'actual_completion_date' => now(), 'completed_by' => Auth::id()]);
            return $order->fresh(['items.rawMaterial', 'finishedProduct', 'finishedWarehouse']);
        });
    }

    public function reports(array $filters = []): array
    {
        $businessId = AppController::businessId();
        return [
            'bom_report' => Bom::query()->with(['finishedProduct', 'items.rawMaterial'])->where('business_id', $businessId)->get(),
            'production_register' => ProductionOrder::query()->with(['finishedProduct', 'branch', 'finishedWarehouse'])->where('business_id', $businessId)->latest('id')->get(),
            'material_consumption' => StockLedger::query()->with('product')->where('business_id', $businessId)->where('transaction_type', 'manufacturing_consumption')->latest('id')->get(),
            'finished_goods' => StockLedger::query()->with('product')->where('business_id', $businessId)->where('transaction_type', 'manufacturing_output')->latest('id')->get(),
            'wastage_scrap' => ProductionWastage::query()->with(['product', 'order'])->where('business_id', $businessId)->latest('id')->get(),
            'production_cost' => ProductionOrder::query()->with('finishedProduct')->where('business_id', $businessId)->where('status', 'completed')->latest('id')->get(),
            'material_shortage' => ProductionOrderItem::query()->with(['rawMaterial', 'order'])->whereIn('production_order_id', ProductionOrder::query()->where('business_id', $businessId)->select('id'))->where('availability_status', 'shortage')->get(),
        ];
    }

    private function ensureFinishedBatch(ProductionOrder $order, array $data): ?int
    {
        $product = $order->finishedProduct;
        $requiresBatch = (bool) ($product->batch_required ?? false) || in_array($product->tracking_type, ['batch', 'batch_serial'], true);
        if (!$requiresBatch) {
            return null;
        }
        $batchNumber = $data['finished_batch_number'] ?? ('MFG-' . $order->order_number);
        $batch = ProductBatch::query()->firstOrCreate(
            ['business_id' => $order->business_id, 'product_id' => $order->finished_product_id, 'batch_no' => $batchNumber],
            array_filter(['batch_number' => $batchNumber, 'manufacturing_date' => $data['manufacturing_date'] ?? now()->toDateString(), 'mfg_date' => $data['manufacturing_date'] ?? now()->toDateString(), 'expiry_date' => $data['expiry_date'] ?? null, 'status' => 'active', 'condition_status' => 'saleable', 'source_voucher_type' => ProductionOrder::class, 'source_voucher_id' => $order->id, 'posted_by' => Auth::id(), 'posted_at' => now()], fn ($v, $k) => Schema::hasColumn('product_batches', $k), ARRAY_FILTER_USE_BOTH)
        );
        return $batch->id;
    }

    private function validateBomItems(int $finishedProductId, array $items, ?int $currentBomId = null): void
    {
        if (!$items) throw ValidationException::withMessages(['items' => 'At least one BOM component is required.']);
        foreach ($items as $index => $item) {
            if ((int) $item['raw_material_product_id'] === $finishedProductId) throw ValidationException::withMessages(["items.$index.raw_material_product_id" => 'Finished product cannot be its own raw material.']);
            $this->assertProduct((int) $item['raw_material_product_id']);
            if ($this->createsCircularReference($finishedProductId, (int) $item['raw_material_product_id'], $currentBomId)) throw ValidationException::withMessages(["items.$index.raw_material_product_id" => 'Circular BOM reference detected.']);
        }
    }

    private function createsCircularReference(int $finishedProductId, int $rawProductId, ?int $ignoreBomId = null, array $seen = []): bool
    {
        if ($rawProductId === $finishedProductId) return true;
        if (in_array($rawProductId, $seen, true)) return false;
        $bomIds = Bom::query()->where('business_id', AppController::businessId())->where('finished_product_id', $rawProductId)->where('status', 'active')->when($ignoreBomId, fn (Builder $q) => $q->where('id', '<>', $ignoreBomId))->pluck('id');
        $children = BomItem::query()->whereIn('bom_id', $bomIds)->pluck('raw_material_product_id');
        foreach ($children as $child) {
            $nextSeen = $seen;
            $nextSeen[] = $rawProductId;
            if ($this->createsCircularReference($finishedProductId, (int) $child, $ignoreBomId, $nextSeen)) return true;
        }
        return false;
    }

    private function assertProduct(int $id): Product
    {
        return Product::query()->where('id', $id)->where(fn (Builder $q) => $this->scopeProduct($q, AppController::businessId()))->firstOrFail();
    }

    private function assertLocation(int $businessId, array $data): void
    {
        if (!empty($data['branch_id'])) Branch::query()->where('business_id', $businessId)->where('id', $data['branch_id'])->firstOrFail();
        Warehouse::query()->where('business_id', $businessId)->where('id', $data['source_warehouse_id'])->firstOrFail();
        Warehouse::query()->where('business_id', $businessId)->where('id', $data['finished_goods_warehouse_id'])->firstOrFail();
    }

    private function nextBomCode(): string
    {
        $last = Bom::query()->where('business_id', AppController::businessId())->where('bom_code', 'like', 'BOM-%')->latest('id')->value('bom_code');
        return 'BOM-' . str_pad((string) (((int) preg_replace('/\D/', '', (string) $last)) + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextOrderNumber(): string
    {
        $last = ProductionOrder::query()->where('business_id', AppController::businessId())->where('order_number', 'like', 'PO-%')->latest('id')->value('order_number');
        return 'PO-' . str_pad((string) (((int) preg_replace('/\D/', '', (string) $last)) + 1), 5, '0', STR_PAD_LEFT);
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
