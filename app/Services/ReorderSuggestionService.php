<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\ProductPurchasePrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReorderSuggestionService
{
    private OrderManagementService $orders;

    public function __construct(OrderManagementService $orders)
    {
        $this->orders = $orders;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();

        return [
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'branch_id', 'name', 'code']),
            'suppliers' => Supplier::query()->where('business_id', $businessId)->where('status', 'active')->orderByRaw('COALESCE(supplier_name, name)')->get(['id', 'supplier_code', 'supplier_name', 'name']),
            'categories' => Schema::hasTable('product_categories')
                ? DB::table('product_categories')->where('business_id', $businessId)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ];
    }

    public function list(array $filters): array
    {
        $query = $this->suggestionQuery($filters);
        $perPage = min(max((int) ($filters['per_page'] ?? 50), 25), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $status = $filters['stock_status'] ?? '';

        $rows = $query->get()->map(fn ($row) => $this->present($row))
            ->filter(fn ($row) => $row['suggested_quantity'] > 0)
            ->when($status, fn (Collection $items) => $items->filter(fn ($row) => $this->matchesStatus($row['status_key'], $status)))
            ->values();

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $summary = [
            'products_to_reorder' => $rows->count(),
            'out_of_stock' => $rows->where('status_key', 'out_of_stock')->count(),
            'low_stock' => $rows->whereIn('status_key', ['critical', 'low_stock', 'reorder_required'])->count(),
            'suggested_purchase_qty' => round($rows->sum('suggested_quantity'), 3),
            'estimated_purchase_value' => round($rows->sum('estimated_value'), 2),
            'missing_reorder_settings' => $this->missingSettingsCount($filters),
        ];

        return ['rows' => $paginator, 'summary' => $summary];
    }

    public function createRequisition(array $items): PurchaseRequisition
    {
        $rows = $this->normalizeSelectedItems($items);
        $branchId = $rows->pluck('branch_id')->filter()->unique()->count() === 1 ? $rows->pluck('branch_id')->filter()->first() : null;

        return DB::transaction(fn () => $this->orders->saveRequisition([
            'branch_id' => $branchId,
            'requisition_date' => now()->toDateString(),
            'department' => 'Purchase',
            'priority' => 'normal',
            'required_date' => null,
            'status' => 'submitted',
            'remarks' => 'Created from Reorder Suggestions',
            'items' => $rows->map(fn ($row) => [
                'product_id' => $row['product_id'],
                'unit_id' => $row['unit_id'] ?? null,
                'quantity' => $row['quantity'],
                'approved_quantity' => $row['quantity'],
                'remarks' => 'Created from Reorder Suggestions',
            ])->values()->all(),
        ]));
    }

    public function createPurchaseOrders(array $items, string $status = 'draft', ?string $expectedDeliveryDate = null): Collection
    {
        $rows = $this->normalizeSelectedItems($items);
        if ($rows->contains(fn ($row) => empty($row['supplier_id']))) {
            throw ValidationException::withMessages(['supplier_id' => 'Select a supplier for every selected reorder item before creating POs.']);
        }

        return DB::transaction(function () use ($rows, $status, $expectedDeliveryDate) {
            return $rows->groupBy(fn ($row) => implode(':', [$row['supplier_id'], $row['branch_id'] ?? 0, $row['warehouse_id']]))
                ->map(function (Collection $supplierRows) use ($status, $expectedDeliveryDate) {
                $first = $supplierRows->first();
                $payload = [
                    'branch_id' => $first['branch_id'] ?? null,
                    'warehouse_id' => $first['warehouse_id'],
                    'purchase_requisition_id' => null,
                    'supplier_id' => $first['supplier_id'],
                    'po_date' => now()->toDateString(),
                    'expected_delivery_date' => $expectedDeliveryDate,
                    'payment_terms' => null,
                    'supplier_reference' => null,
                    'currency' => 'INR',
                    'status' => $status,
                    'terms_conditions' => null,
                    'remarks' => 'Created from Reorder Suggestions',
                    'items' => $supplierRows->map(fn ($row) => [
                        'product_id' => $row['product_id'],
                        'product_variant_id' => null,
                        'unit_id' => $row['unit_id'] ?? null,
                        'ordered_quantity' => $row['quantity'],
                        'purchase_rate' => $row['purchase_rate'],
                        'gst_rate' => $row['gst_rate'] ?? 0,
                        'remarks' => 'Created from Reorder Suggestions',
                    ])->values()->all(),
                ];

                if (Schema::hasColumn('purchase_orders', 'source_type')) {
                    $payload['source_type'] = 'reorder_suggestion';
                }

                if (Schema::hasColumn('purchase_orders', 'source_reference')) {
                    $payload['source_reference'] = 'Reorder Suggestions';
                }

                return $this->orders->savePurchaseOrder($payload);
            })->values();
        });
    }

    private function suggestionQuery(array $filters)
    {
        $businessId = AppController::businessId();
        $productColumns = Schema::getColumnListing('products');
        $productBusinessColumn = in_array('business_id', $productColumns, true) ? 'business_id' : 'company_id';

        $stock = DB::table('stock_ledgers')
            ->selectRaw('
                business_id,
                product_id,
                branch_id,
                warehouse_id,
                COALESCE(SUM(CASE WHEN COALESCE(stock_status, "saleable") = "saleable" THEN quantity_in - quantity_out ELSE 0 END), 0) as saleable_stock,
                COALESCE(SUM(CASE WHEN COALESCE(stock_status, "saleable") <> "lost" THEN quantity_in - quantity_out ELSE 0 END), 0) as physical_stock
            ')
            ->where('business_id', $businessId)
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->groupBy('business_id', 'product_id', 'branch_id', 'warehouse_id');

        $reserved = DB::table('stock_reservations')
            ->selectRaw('business_id, product_id, branch_id, warehouse_id, COALESCE(SUM(reserved_quantity - fulfilled_quantity - released_quantity),0) as reserved_stock')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->groupBy('business_id', 'product_id', 'branch_id', 'warehouse_id');

        $incoming = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->selectRaw('purchase_orders.business_id, purchase_order_items.product_id, purchase_orders.branch_id, purchase_orders.warehouse_id, COALESCE(SUM(CASE WHEN purchase_order_items.ordered_quantity > purchase_order_items.received_quantity THEN purchase_order_items.ordered_quantity - purchase_order_items.received_quantity ELSE 0 END),0) as incoming_po_qty')
            ->where('purchase_orders.business_id', $businessId)
            ->whereIn('purchase_orders.status', ['draft', 'approved', 'sent', 'confirmed', 'partially_received'])
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('purchase_orders.branch_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->where('purchase_orders.warehouse_id', $id))
            ->when(Schema::hasColumn('purchase_orders', 'source_type'), fn ($q) => $q->whereIn(DB::raw('COALESCE(purchase_orders.source_type, "manual")'), ['manual', 'reorder_suggestion', 'requisition']))
            ->groupBy('purchase_orders.business_id', 'purchase_order_items.product_id', 'purchase_orders.branch_id', 'purchase_orders.warehouse_id');

        $pendingReq = DB::table('purchase_requisition_items')
            ->join('purchase_requisitions', 'purchase_requisitions.id', '=', 'purchase_requisition_items.purchase_requisition_id')
            ->selectRaw('purchase_requisitions.business_id, purchase_requisition_items.product_id, purchase_requisitions.branch_id, COALESCE(SUM(COALESCE(purchase_requisition_items.approved_quantity, purchase_requisition_items.quantity)),0) as pending_requisition_qty')
            ->where('purchase_requisitions.business_id', $businessId)
            ->whereIn('purchase_requisitions.status', ['submitted', 'pending_approval', 'approved'])
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('purchase_requisitions.branch_id', $id))
            ->groupBy('purchase_requisitions.business_id', 'purchase_requisition_items.product_id', 'purchase_requisitions.branch_id');

        $lastPurchase = DB::table('product_purchase_prices as ppp')
            ->where('ppp.business_id', $businessId)
            ->whereRaw('ppp.id = (select max(ppp2.id) from product_purchase_prices ppp2 where ppp2.business_id = ppp.business_id and ppp2.product_id = ppp.product_id)')
            ->select('ppp.product_id', 'ppp.supplier_id', 'ppp.unit_cost', 'ppp.purchase_date');

        return DB::table('products')
            ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'products.id'))
            ->leftJoinSub($reserved, 'reserved', fn ($join) => $join->on('reserved.product_id', '=', 'products.id')
                ->whereRaw('COALESCE(reserved.branch_id, 0) = COALESCE(stock.branch_id, 0)')
                ->whereRaw('COALESCE(reserved.warehouse_id, 0) = COALESCE(stock.warehouse_id, 0)'))
            ->leftJoinSub($incoming, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'products.id')
                ->whereRaw('COALESCE(incoming.branch_id, 0) = COALESCE(stock.branch_id, 0)')
                ->whereRaw('COALESCE(incoming.warehouse_id, 0) = COALESCE(stock.warehouse_id, 0)'))
            ->leftJoinSub($pendingReq, 'pending_req', fn ($join) => $join->on('pending_req.product_id', '=', 'products.id')
                ->whereRaw('COALESCE(pending_req.branch_id, 0) = COALESCE(stock.branch_id, 0)'))
            ->leftJoinSub($lastPurchase, 'last_purchase', fn ($join) => $join->on('last_purchase.product_id', '=', 'products.id'))
            ->leftJoin('suppliers', 'suppliers.id', '=', 'last_purchase.supplier_id')
            ->leftJoin('branches', 'branches.id', '=', 'stock.branch_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'stock.warehouse_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->where("products.$productBusinessColumn", $businessId)
            ->where('products.status', 'active')
            ->where('products.product_type', 'goods')
            ->where('products.item_type', 'stock')
            ->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('products.id', $id))
            ->when($filters['search'] ?? null, function ($q, string $search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('products.name', 'like', "%$search%")
                        ->orWhere('products.sku', 'like', "%$search%")
                        ->orWhere('products.primary_barcode', 'like', "%$search%")
                        ->orWhere('products.barcode', 'like', "%$search%")
                        ->orWhere('products.brand', 'like', "%$search%")
                        ->orWhere('products.category', 'like', "%$search%")
                        ->orWhere('product_categories.name', 'like', "%$search%");
                });
            })
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('products.category_id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('last_purchase.supplier_id', $id))
            ->selectRaw('
                products.id as product_id,
                products.name as product_name,
                products.sku,
                COALESCE(products.primary_barcode, products.barcode) as barcode,
                products.unit_id,
                products.unit,
                COALESCE(product_categories.name, products.category) as category_name,
                stock.branch_id,
                stock.warehouse_id,
                branches.name as branch_name,
                warehouses.name as warehouse_name,
                COALESCE(stock.saleable_stock, 0) as current_stock,
                COALESCE(stock.physical_stock, 0) as physical_stock,
                COALESCE(reserved.reserved_stock, 0) as reserved_stock,
                COALESCE(incoming.incoming_po_qty, 0) as incoming_po_qty,
                COALESCE(pending_req.pending_requisition_qty, 0) as pending_requisition_qty,
                COALESCE(NULLIF(products.reorder_stock, 0), NULLIF(products.reorder_level, 0), products.minimum_stock, 0) as reorder_level,
                products.minimum_stock,
                products.maximum_stock,
                COALESCE(NULLIF(products.maximum_stock, 0), NULLIF(products.reorder_stock, 0), NULLIF(products.reorder_level, 0), products.minimum_stock, 0) as target_stock,
                COALESCE(last_purchase.unit_cost, products.purchase_price, products.cost_price, products.default_purchase_price, 0) as purchase_rate,
                last_purchase.supplier_id as preferred_supplier_id,
                COALESCE(suppliers.supplier_name, suppliers.name) as preferred_supplier,
                last_purchase.purchase_date,
                products.gst_rate
            ')
            ->orderBy('products.name');
    }

    private function present(object $row): array
    {
        $current = round((float) $row->current_stock, 3);
        $physical = round((float) $row->physical_stock, 3);
        $reserved = round((float) $row->reserved_stock, 3);
        $incoming = round((float) $row->incoming_po_qty, 3);
        $pendingReq = round((float) $row->pending_requisition_qty, 3);
        $available = round($current - $reserved, 3);
        $pendingOrder = round($incoming, 3);
        $projected = round($available + $pendingOrder + $pendingReq, 3);
        $target = (float) $row->target_stock;
        $suggested = round(max(0, $target - $projected), 3);
        $rate = round((float) $row->purchase_rate, 2);
        $statusKey = $this->statusKey($available, $suggested, (float) $row->reorder_level);

        return [
            'product_id' => (int) $row->product_id,
            'product_name' => $row->product_name,
            'sku' => $row->sku,
            'barcode' => $row->barcode,
            'unit_id' => $row->unit_id,
            'unit' => $row->unit ?: 'PCS',
            'category' => $row->category_name,
            'branch_id' => $row->branch_id,
            'branch' => $row->branch_name ?: 'All Branches',
            'warehouse_id' => $row->warehouse_id,
            'warehouse' => $row->warehouse_name ?: 'All Warehouses',
            'current_stock' => $current,
            'physical_stock' => $physical,
            'reserved_stock' => $reserved,
            'available_stock' => $available,
            'incoming_po_qty' => $incoming,
            'pending_order_qty' => $pendingOrder,
            'pending_requisition_qty' => $pendingReq,
            'projected_stock' => $projected,
            'reorder_level' => round((float) $row->reorder_level, 3),
            'minimum_stock' => round((float) $row->minimum_stock, 3),
            'target_stock' => round($target, 3),
            'suggested_quantity' => $suggested,
            'purchase_rate' => $rate,
            'estimated_value' => round($suggested * $rate, 2),
            'preferred_supplier_id' => $row->preferred_supplier_id,
            'preferred_supplier' => $row->preferred_supplier ?: 'Not assigned',
            'last_purchase_date' => $row->purchase_date,
            'gst_rate' => (float) $row->gst_rate,
            'status_key' => $statusKey,
            'status' => $this->statusLabel($statusKey),
        ];
    }

    private function statusKey(float $available, float $suggested, float $reorder): string
    {
        if ($available <= 0) return 'out_of_stock';
        if ($reorder > 0 && $available <= ($reorder / 2)) return 'critical';
        if ($reorder > 0 && $available <= $reorder) return 'low_stock';
        return $suggested > 0 ? 'reorder_required' : 'healthy';
    }

    private function statusLabel(string $key): string
    {
        return [
            'out_of_stock' => 'Out of Stock',
            'critical' => 'Critical',
            'low_stock' => 'Low Stock',
            'reorder_required' => 'Reorder Required',
            'healthy' => 'Healthy',
        ][$key] ?? 'Reorder Required';
    }

    private function matchesStatus(string $actual, string $filter): bool
    {
        return $filter === 'all' || $filter === $actual || ($filter === 'reorder_required' && $actual !== 'healthy');
    }

    private function missingSettingsCount(array $filters): int
    {
        $businessId = AppController::businessId();
        $columns = Schema::getColumnListing('products');
        $businessColumn = in_array('business_id', $columns, true) ? 'business_id' : 'company_id';

        return DB::table('products')
            ->where("products.$businessColumn", $businessId)
            ->where('products.status', 'active')
            ->where('products.product_type', 'goods')
            ->where('products.item_type', 'stock')
            ->whereRaw('COALESCE(products.reorder_stock, products.reorder_level, products.minimum_stock, products.maximum_stock, 0) = 0')
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('products.category_id', $id))
            ->count();
    }

    private function normalizeSelectedItems(array $items): Collection
    {
        $businessId = AppController::businessId();
        $rows = collect($items)->map(function ($item, int $index) use ($businessId) {
            $quantity = (float) ($item['final_order_quantity'] ?? $item['quantity'] ?? $item['suggested_quantity'] ?? 0);
            if ($quantity < 0) {
                throw ValidationException::withMessages(["items.$index.quantity" => 'Final order quantity cannot be negative.']);
            }
            if ($quantity <= 0) {
                throw ValidationException::withMessages(["items.$index.quantity" => 'Quantity must be greater than 0.']);
            }

            DB::table('products')->where(function ($query) use ($businessId) {
                $query->where('business_id', $businessId)->orWhere('company_id', $businessId);
            })->where('id', $item['product_id'])->firstOrFail();

            if (!empty($item['supplier_id'])) {
                Supplier::query()->where('business_id', $businessId)->where('id', $item['supplier_id'])->firstOrFail();
            }

            if (!empty($item['warehouse_id'])) {
                Warehouse::query()->where('business_id', $businessId)->where('id', $item['warehouse_id'])->firstOrFail();
            }

            return [
                'product_id' => (int) $item['product_id'],
                'unit_id' => $item['unit_id'] ?? null,
                'branch_id' => $item['branch_id'] ?? null,
                'warehouse_id' => $item['warehouse_id'] ?? null,
                'supplier_id' => $item['supplier_id'] ?? null,
                'quantity' => round($quantity, 3),
                'purchase_rate' => (float) ($item['purchase_rate'] ?? 0),
                'gst_rate' => (float) ($item['gst_rate'] ?? 0),
            ];
        });

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Select at least one reorder item.']);
        }

        $this->assertQuantitiesStillRequired($rows);

        return $rows;
    }

    private function assertQuantitiesStillRequired(Collection $rows): void
    {
        foreach ($rows->groupBy(fn ($row) => implode(':', [$row['product_id'], $row['branch_id'] ?? 0, $row['warehouse_id'] ?? 0])) as $group) {
            $first = $group->first();
            $requested = round($group->sum('quantity'), 3);
            $latest = $this->suggestionQuery([
                'product_id' => $first['product_id'],
                'branch_id' => $first['branch_id'],
                'warehouse_id' => $first['warehouse_id'],
            ])->get()->map(fn ($row) => $this->present($row))->first();

            $remaining = round((float) ($latest['suggested_quantity'] ?? 0), 3);

            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'items' => sprintf('%s already has enough saleable stock or pending order quantity. Refresh reorder suggestions before creating another order.', $latest['product_name'] ?? 'Selected product'),
                ]);
            }

            if ($requested > $remaining) {
                throw ValidationException::withMessages([
                    'items' => sprintf('Requested reorder quantity %.3f exceeds the current remaining requirement %.3f for %s.', $requested, $remaining, $latest['product_name'] ?? 'selected product'),
                ]);
            }
        }
    }
}
