<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryOrderService
{
    private OrderManagementService $orders;
    private PurchaseService $purchases;

    public function __construct(OrderManagementService $orders, PurchaseService $purchases)
    {
        $this->orders = $orders;
        $this->purchases = $purchases;
    }

    public function references(): array
    {
        return array_merge($this->orders->references(), [
            'approved_requisitions' => PurchaseRequisition::query()
                ->where('business_id', $businessId)
                ->where('status', 'approved')
                ->with('items.product')
                ->latest('id')
                ->limit(100)
                ->get(),
            'sources' => [
                ['value' => 'manual', 'label' => 'Manual'],
                ['value' => 'reorder_suggestion', 'label' => 'Reorder Suggestion'],
                ['value' => 'requisition', 'label' => 'Requisition'],
            ],
        ]);
    }

    public function list(array $filters): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 25), 100);
        $query = $this->baseQuery($filters);
        $paginator = $query->latest('purchase_orders.id')->paginate($perPage);
        $paginator->getCollection()->transform(fn (PurchaseOrder $order) => $this->present($order));

        return [
            'orders' => $paginator,
            'summary' => $this->summary($filters),
        ];
    }

    public function save(array $data, ?int $id = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $status = $data['status'] ?? 'draft';
            $sourceType = $data['source_type'] ?? 'manual';
            $sourceReference = $data['source_reference'] ?? null;
            $priority = $data['priority'] ?? 'normal';

            $payload = [
                'branch_id' => $data['branch_id'],
                'warehouse_id' => $data['warehouse_id'],
                'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'po_date' => $data['order_date'],
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'supplier_reference' => $sourceReference,
                'currency' => 'INR',
                'status' => $status === 'ordered' ? 'confirmed' : $status,
                'terms_conditions' => null,
                'remarks' => $data['remarks'] ?? null,
                'items' => $data['items'],
            ];

            $order = $this->orders->savePurchaseOrder($payload, $id);

            $updates = [
                'source_type' => $sourceType,
                'source_reference' => $sourceReference,
                'priority' => $priority,
            ];

            if (in_array($status, ['approved', 'ordered', 'confirmed'], true)) {
                $updates['approved_by'] = Auth::id();
                $updates['approved_at'] = now();
            }

            if (in_array($status, ['ordered', 'confirmed'], true)) {
                $updates['status'] = 'confirmed';
                $updates['ordered_by'] = Auth::id();
                $updates['ordered_at'] = now();
            }

            PurchaseOrder::query()->where('business_id', $businessId)->where('id', $order->id)->update(array_filter(
                $updates,
                fn ($value, $key) => Schema::hasColumn('purchase_orders', $key),
                ARRAY_FILTER_USE_BOTH
            ));

            return $this->fresh($order->id);
        });
    }

    public function markOrdered(int $id): PurchaseOrder
    {
        return DB::transaction(function () use ($id) {
            $order = $this->fresh($id);
            if (in_array($order->status, ['fully_received', 'received', 'closed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['status' => 'This inventory order cannot be marked ordered.']);
            }

            $order->update(array_filter([
                'status' => 'confirmed',
                'confirmation_status' => 'accepted',
                'ordered_by' => Auth::id(),
                'ordered_at' => now(),
            ], fn ($value, $key) => Schema::hasColumn('purchase_orders', $key), ARRAY_FILTER_USE_BOTH));

            return $this->fresh($id);
        });
    }

    public function cancel(int $id): PurchaseOrder
    {
        return DB::transaction(function () use ($id) {
            $order = $this->fresh($id);
            if (in_array($order->status, ['fully_received', 'received', 'closed'], true)) {
                throw ValidationException::withMessages(['status' => 'Received inventory orders cannot be cancelled.']);
            }

            $order->update(array_filter([
                'status' => 'cancelled',
                'cancelled_by' => Auth::id(),
                'cancelled_at' => now(),
            ], fn ($value, $key) => Schema::hasColumn('purchase_orders', $key), ARRAY_FILTER_USE_BOTH));

            return $this->fresh($id);
        });
    }

    public function productSearch(string $search)
    {
        return $this->purchases->searchProducts($search);
    }

    private function baseQuery(array $filters): Builder
    {
        $businessId = AppController::businessId();

        return PurchaseOrder::query()
            ->with(['supplier', 'branch', 'warehouse', 'requisition', 'items.product'])
            ->where('purchase_orders.business_id', $businessId)
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('po_number', 'like', "%$search%")
                        ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('supplier_name', 'like', "%$search%")->orWhere('name', 'like', "%$search%"))
                        ->orWhereHas('items.product', fn (Builder $product) => $product->where('name', 'like', "%$search%")->orWhere('sku', 'like', "%$search%"));
                });
            })
            ->when($filters['product'] ?? null, fn (Builder $q, string $product) => $q->whereHas('items.product', fn (Builder $p) => $p->where('name', 'like', "%$product%")->orWhere('sku', 'like', "%$product%")))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, int $id) => $q->where('warehouse_id', $id))
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->whereIn('status', $this->statusFilterValues($status)))
            ->when(($filters['source_type'] ?? null) && Schema::hasColumn('purchase_orders', 'source_type'), fn (Builder $q, string $source) => $q->where('source_type', $source))
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('po_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $date) => $q->whereDate('po_date', '<=', $date))
            ->when($filters['expected_date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('expected_delivery_date', $date));
    }

    private function summary(array $filters): array
    {
        $orders = $this->baseQuery($filters)->get();

        return [
            'total_orders' => $orders->count(),
            'draft' => $orders->where('status', 'draft')->count(),
            'pending_ordered' => $orders->whereIn('status', ['approved', 'sent', 'confirmed'])->count(),
            'partially_received' => $orders->whereIn('status', ['partially_received', 'partial_received'])->count(),
            'received' => $orders->whereIn('status', ['fully_received', 'received', 'closed'])->count(),
            'pending_quantity' => round($orders->sum(fn (PurchaseOrder $order) => $order->items->sum(fn ($item) => max(0, (float) $item->ordered_quantity - (float) $item->received_quantity))), 3),
            'open_order_value' => round($orders->whereIn('status', ['draft', 'approved', 'sent', 'confirmed', 'partially_received'])->sum('grand_total'), 2),
        ];
    }

    private function present(PurchaseOrder $order): array
    {
        $totalQty = $order->items->sum(fn ($item) => (float) $item->ordered_quantity);
        $receivedQty = $order->items->sum(fn ($item) => (float) $item->received_quantity);

        return [
            'id' => $order->id,
            'order_number' => $order->po_number,
            'po_number' => $order->po_number,
            'source_type' => $order->source_type ?: ($order->purchase_requisition_id ? 'requisition' : 'manual'),
            'source_reference' => $order->source_reference ?: optional($order->requisition)->requisition_number,
            'supplier_id' => $order->supplier_id,
            'supplier' => $order->supplier,
            'branch_id' => $order->branch_id,
            'branch' => $order->branch,
            'warehouse_id' => $order->warehouse_id,
            'warehouse' => $order->warehouse,
            'items_count' => $order->items->count(),
            'total_qty' => round($totalQty, 3),
            'received_qty' => round($receivedQty, 3),
            'pending_qty' => round(max(0, $totalQty - $receivedQty), 3),
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) $order->discount_amount,
            'taxable_amount' => (float) $order->taxable_amount,
            'tax_amount' => (float) $order->tax_amount,
            'grand_total' => (float) $order->grand_total,
            'order_date' => optional($order->po_date)->format('Y-m-d'),
            'expected_delivery_date' => optional($order->expected_delivery_date)->format('Y-m-d'),
            'payment_terms' => $order->payment_terms,
            'priority' => $order->priority ?: 'normal',
            'status' => $this->displayStatus($order->status),
            'raw_status' => $order->status,
            'remarks' => $order->remarks,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product,
                'sku' => optional($item->product)->sku,
                'unit_id' => $item->unit_id,
                'ordered_quantity' => (float) $item->ordered_quantity,
                'received_quantity' => (float) $item->received_quantity,
                'pending_quantity' => max(0, (float) $item->ordered_quantity - (float) $item->received_quantity),
                'purchase_rate' => (float) $item->purchase_rate,
                'discount_amount' => (float) $item->discount_amount,
                'taxable_amount' => (float) $item->taxable_amount,
                'tax_amount' => (float) $item->tax_amount,
                'gst_rate' => (float) ($item->tax_snapshot['gst_rate'] ?? 0),
                'line_total' => (float) $item->line_total,
                'remarks' => $item->remarks,
            ])->values(),
        ];
    }

    private function fresh(int $id): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->where('business_id', AppController::businessId())
            ->with(['supplier', 'branch', 'warehouse', 'requisition', 'items.product'])
            ->findOrFail($id);
    }

    private function statusFilterValues(string $status): array
    {
        return [
            'ordered' => ['confirmed', 'sent'],
            'received' => ['fully_received', 'received'],
            'partially_received' => ['partially_received', 'partial_received'],
        ][$status] ?? [$status];
    }

    private function displayStatus(string $status): string
    {
        return [
            'confirmed' => 'ordered',
            'fully_received' => 'received',
            'partial_received' => 'partially_received',
        ][$status] ?? $status;
    }
}
