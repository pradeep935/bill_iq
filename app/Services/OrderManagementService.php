<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\BackOrder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\GoodsReceipt;
use App\Models\OrderNotification;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderManagementService
{
    private StockService $stock;

    public function __construct(StockService $stock)
    {
        $this->stock = $stock;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        return [
            'customers' => Customer::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('customer_name')->limit(300)->get(['id', 'customer_name', 'mobile', 'gstin', 'billing_address', 'shipping_address']),
            'suppliers' => Supplier::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->limit(300)->get(['id', 'name', 'phone', 'gstin']),
            'branches' => Branch::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'warehouses' => Warehouse::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('name')->get(['id', 'branch_id', 'name', 'code']),
            'products' => $this->productQuery()->orderBy('name')->limit(300)->get(['id', 'name', 'sku', 'primary_barcode', 'barcode', 'unit_id', 'selling_price', 'purchase_price', 'gst_rate', 'cess_rate']),
        ];
    }

    public function searchProducts(string $q)
    {
        return $this->productQuery()->where(function (Builder $query) use ($q) {
            $query->where('name', 'like', '%' . $q . '%')->orWhere('sku', 'like', '%' . $q . '%')->orWhere('primary_barcode', $q)->orWhere('barcode', $q)->orWhereHas('barcodes', fn (Builder $b) => $b->where('barcode', $q));
        })->limit(30)->get();
    }

    public function quotations(array $filters)
    {
        return $this->applyDocumentFilters(
            Quotation::query()->with(['customer', 'branch', 'items.product'])->where('business_id', AppController::businessId()),
            $filters,
            'quotation_number',
            'status',
            'quotation_date',
            'customer_id'
        )->latest('id')->paginate(20);
    }

    public function saveQuotation(array $data, ?int $id = null): Quotation
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $customer = $this->customer($data['customer_id']);
            $items = $this->calculateItems($data['items'], 'sales');
            unset($data['items']);
            $quotation = $id ? Quotation::query()->where('business_id', $businessId)->findOrFail($id) : new Quotation(['business_id' => $businessId, 'quotation_number' => $this->nextNumber('QT', Quotation::class, 'quotation_number'), 'created_by' => Auth::id()]);
            if (in_array($quotation->status, ['converted', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Converted or cancelled quotation cannot be edited.']);
            $totals = $this->totals($items, $data['discount_type'] ?? null, (float) ($data['discount_value'] ?? 0), (float) ($data['shipping_amount'] ?? 0));
            $quotation->fill(array_merge($data, $totals, [
                'customer_snapshot_json' => $customer->toArray(),
                'billing_address_snapshot' => $customer->billing_address ?? null,
                'shipping_address_snapshot' => $customer->shipping_address ?? null,
                'approval_status' => in_array($data['status'], ['sent', 'accepted'], true) ? 'approved' : 'pending',
                'approved_by' => in_array($data['status'], ['sent', 'accepted'], true) ? Auth::id() : null,
                'approved_at' => in_array($data['status'], ['sent', 'accepted'], true) ? now() : null,
                'expiry_status' => !empty($data['valid_until']) && $data['valid_until'] < now()->toDateString() ? 'expired' : 'valid',
            ]))->save();
            $quotation->items()->delete();
            $quotation->items()->createMany($items);
            $this->history('quotation', $quotation->id, null, $quotation->status, 'Quotation saved');
            $this->notification('quotation', $quotation->id, 'quotation_saved', ['number' => $quotation->quotation_number]);
            return $quotation->fresh(['items.product', 'customer']);
        });
    }

    public function convertQuotation(int $id): SalesOrder
    {
        return DB::transaction(function () use ($id) {
            $quotation = Quotation::query()->where('business_id', AppController::businessId())->with('items')->findOrFail($id);
            if ($quotation->converted_sales_order_id) throw ValidationException::withMessages(['quotation' => 'Quotation is already converted.']);
            $warehouseId = Warehouse::query()
                ->where('business_id', $quotation->business_id)
                ->when($quotation->branch_id, fn (Builder $query) => $query->where('branch_id', $quotation->branch_id))
                ->value('id');

            if (!$warehouseId) throw ValidationException::withMessages(['warehouse_id' => 'Please create an active warehouse before converting quotation to sales order.']);

            $order = $this->saveSalesOrder([
                'branch_id' => $quotation->branch_id, 'warehouse_id' => $warehouseId,
                'quotation_id' => $quotation->id, 'customer_id' => $quotation->customer_id, 'order_date' => now()->toDateString(),
                'expected_delivery_date' => null, 'sales_person_id' => $quotation->sales_person_id, 'order_status' => 'approved',
                'shipping' => $quotation->shipping_amount, 'remarks' => 'Converted from quotation ' . $quotation->quotation_number,
                'items' => $quotation->items->map(fn ($item) => ['product_id' => $item->product_id, 'product_variant_id' => $item->variant_id, 'batch_id' => $item->batch_id, 'unit_id' => $item->unit_id, 'description' => $item->description, 'ordered_quantity' => (float) $item->quantity, 'unit_price' => (float) $item->unit_price, 'discount_amount' => (float) $item->discount, 'gst_rate' => $item->gst_snapshot['gst_rate'] ?? 0])->all(),
            ]);
            $quotation->update(['status' => 'converted', 'converted_sales_order_id' => $order->id]);
            return $order;
        });
    }

    public function salesOrders(array $filters)
    {
        return $this->applyDocumentFilters(
            SalesOrder::query()->with(['customer', 'warehouse', 'items.product'])->where('business_id', AppController::businessId()),
            $filters,
            'order_number',
            'order_status',
            'order_date',
            'customer_id'
        )->latest('id')->paginate(20);
    }

    public function saveSalesOrder(array $data, ?int $id = null): SalesOrder
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->customer($data['customer_id']); $this->warehouse($data['warehouse_id'], $data['branch_id'] ?? null);
            $items = $this->calculateItems($data['items'], 'sales_order');
            unset($data['items']);
            $order = $id ? SalesOrder::query()->where('business_id', $businessId)->findOrFail($id) : new SalesOrder(['business_id' => $businessId, 'order_number' => $this->nextNumber('SO', SalesOrder::class, 'order_number'), 'created_by' => Auth::id()]);
            if (in_array($order->order_status, ['delivered', 'closed', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Finalized sales order cannot be edited.']);
            $totals = $this->totals($items, null, 0, (float) ($data['shipping'] ?? 0));
            $order->fill(array_merge($data, ['subtotal' => $totals['subtotal'], 'tax' => $totals['cgst'] + $totals['sgst'] + $totals['igst'] + $totals['cess'], 'grand_total' => $totals['grand_total'], 'reservation_status' => $order->reservation_status ?: 'none', 'dispatch_status' => $order->dispatch_status ?: 'pending', 'invoice_status' => $order->invoice_status ?: 'not_invoiced']))->save();
            $order->items()->delete();
            $order->items()->createMany($items);
            if ($data['order_status'] === 'approved') $this->approveSalesOrder($order->id);
            return $order->fresh(['items.product', 'customer', 'warehouse']);
        });
    }

    public function approveSalesOrder(int $id): SalesOrder
    {
        return DB::transaction(function () use ($id) {
            $order = SalesOrder::query()->where('business_id', AppController::businessId())->with('items.product')->findOrFail($id);
            StockReservation::query()
                ->where('business_id', $order->business_id)
                ->where('reference_type', SalesOrder::class)
                ->where('reference_id', $order->id)
                ->where('status', 'active')
                ->update(['status' => 'released', 'released_quantity' => DB::raw('reserved_quantity')]);
            BackOrder::query()
                ->where('business_id', $order->business_id)
                ->where('source_type', SalesOrder::class)
                ->where('source_id', $order->id)
                ->where('status', 'open')
                ->update(['status' => 'closed']);
            $reserved = $partial = 0;
            foreach ($order->items as $item) {
                $qty = (float) $item->ordered_quantity - (float) $item->cancelled_quantity;
                $available = $this->stock->getCurrentStock(['business_id' => $order->business_id, 'branch_id' => $order->branch_id, 'warehouse_id' => $order->warehouse_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'batch_id' => $item->batch_id]);
                $reserve = min($available, $qty);
                if ($reserve > 0) {
                    StockReservation::query()->create(['business_id' => $order->business_id, 'branch_id' => $order->branch_id, 'warehouse_id' => $order->warehouse_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'batch_id' => $item->batch_id, 'reference_type' => SalesOrder::class, 'reference_id' => $order->id, 'reserved_quantity' => $reserve, 'status' => 'active', 'created_by' => Auth::id()]);
                    $item->update(['reserved_quantity' => $reserve]);
                    $reserved++;
                }
                if ($reserve < $qty) {
                    $partial++;
                    BackOrder::query()->create(['business_id' => $order->business_id, 'source_type' => SalesOrder::class, 'source_id' => $order->id, 'product_id' => $item->product_id, 'pending_quantity' => $qty - $reserve, 'expected_date' => $order->expected_delivery_date, 'priority' => 'normal']);
                }
            }
            $old = $order->order_status;
            $order->update(['order_status' => 'approved', 'reservation_status' => $reserved === 0 ? 'none' : ($partial ? 'partial' : 'reserved'), 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->history('sales_order', $order->id, $old, 'approved', 'Sales order approved and reservation attempted');
            return $order->fresh(['items.product']);
        });
    }

    public function deliveryChallans(array $filters)
    {
        return $this->applyDocumentFilters(
            DeliveryChallan::query()->with(['customer', 'order', 'warehouse', 'items.product'])->where('business_id', AppController::businessId()),
            $filters,
            'challan_number',
            'status',
            'challan_date',
            'customer_id'
        )->latest('id')->paginate(20);
    }

    public function saveDeliveryChallan(array $data, ?int $id = null): DeliveryChallan
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->customer($data['customer_id']); $this->warehouse($data['warehouse_id'], $data['branch_id'] ?? null);
            $items = $data['items']; unset($data['items']);
            $challan = $id ? DeliveryChallan::query()->where('business_id', $businessId)->findOrFail($id) : new DeliveryChallan(['business_id' => $businessId, 'challan_number' => $this->nextNumber('DC', DeliveryChallan::class, 'challan_number'), 'created_by' => Auth::id()]);
            if (in_array($challan->status, ['dispatched', 'delivered', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Posted challan cannot be edited.']);
            $challan->fill($data)->save();
            $challan->items()->delete();
            $challan->items()->createMany($items);
            if (in_array($data['status'], ['dispatched', 'delivered'], true)) $this->dispatchChallan($challan->id, $data['status']);
            return $challan->fresh(['items.product', 'customer', 'warehouse']);
        });
    }

    public function dispatchChallan(int $id, string $status = 'dispatched'): DeliveryChallan
    {
        return DB::transaction(function () use ($id, $status) {
            $challan = DeliveryChallan::query()->where('business_id', AppController::businessId())->with(['items', 'order.items'])->findOrFail($id);
            if ($this->stockPosted(DeliveryChallan::class, $challan->id)) return $challan;
            foreach ($challan->items as $item) {
                if ($item->sales_order_item_id) {
                    $orderItem = $challan->order->items->firstWhere('id', $item->sales_order_item_id);
                    if ($orderItem && (float) $item->dispatch_quantity > ((float) $orderItem->ordered_quantity - (float) $orderItem->delivered_quantity - (float) $orderItem->cancelled_quantity)) throw ValidationException::withMessages(['dispatch_quantity' => 'Cannot over-deliver sales order item.']);
                }
                $this->stock->decreaseStock(['business_id' => $challan->business_id, 'branch_id' => $challan->branch_id, 'warehouse_id' => $challan->warehouse_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'batch_id' => $item->batch_id, 'serial_id' => $item->serial_id, 'transaction_type' => 'delivery_challan', 'reference_type' => DeliveryChallan::class, 'reference_id' => $challan->id, 'quantity' => (float) $item->dispatch_quantity, 'unit_cost' => (float) $item->unit_cost, 'warehouse_location' => $item->warehouse_location, 'transaction_date' => $challan->challan_date, 'remarks' => 'Delivery challan ' . $challan->challan_number]);
                if ($item->sales_order_item_id) $item->orderItem()->increment('delivered_quantity', (float) $item->dispatch_quantity);
            }
            if ($challan->order) $this->refreshSalesOrderFulfillment($challan->order);
            $challan->update(['status' => $status, 'dispatched_by' => Auth::id(), 'dispatched_at' => now(), 'delivered_by' => $status === 'delivered' ? Auth::id() : null, 'delivered_at' => $status === 'delivered' ? now() : null]);
            return $challan->fresh(['items.product']);
        });
    }

    public function requisitions(array $filters) { return $this->applyDocumentFilters(PurchaseRequisition::query()->with(['branch', 'items.product'])->where('business_id', AppController::businessId()), $filters, 'requisition_number', 'status', 'requisition_date')->latest('id')->paginate(20); }

    public function saveRequisition(array $data, ?int $id = null): PurchaseRequisition
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            if (!empty($data['branch_id'])) $this->branch($data['branch_id']);
            $items = $data['items']; unset($data['items']);
            $req = $id ? PurchaseRequisition::query()->where('business_id', $businessId)->findOrFail($id) : new PurchaseRequisition(['business_id' => $businessId, 'requisition_number' => $this->nextNumber('PR', PurchaseRequisition::class, 'requisition_number'), 'created_by' => Auth::id()]);
            $req->fill($data)->save();
            $req->items()->delete(); $req->items()->createMany($items);
            if ($data['status'] === 'approved') $req->update(['approved_by' => Auth::id(), 'approved_at' => now()]);
            return $req->fresh(['items.product']);
        });
    }

    public function purchaseOrders(array $filters) { return $this->applyDocumentFilters(PurchaseOrder::query()->with(['supplier', 'warehouse', 'items.product'])->where('business_id', AppController::businessId()), $filters, 'po_number', 'status', 'po_date', 'supplier_id')->latest('id')->paginate(20); }

    public function savePurchaseOrder(array $data, ?int $id = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->supplier($data['supplier_id']); $this->warehouse($data['warehouse_id'], $data['branch_id'] ?? null);
            $items = $this->calculateItems($data['items'], 'purchase'); unset($data['items']);
            $po = $id ? PurchaseOrder::query()->where('business_id', $businessId)->findOrFail($id) : new PurchaseOrder(['business_id' => $businessId, 'po_number' => $this->nextNumber('PO', PurchaseOrder::class, 'po_number'), 'created_by' => Auth::id()]);
            $totals = $this->totals($items, null, 0, 0);
            $po->fill(array_merge($data, ['subtotal' => $totals['subtotal'], 'taxable_amount' => $totals['taxable_amount'], 'tax_amount' => $totals['cgst'] + $totals['sgst'] + $totals['igst'] + $totals['cess'], 'grand_total' => $totals['grand_total']]))->save();
            $po->items()->delete(); $po->items()->createMany($items);
            if (in_array($data['status'], ['approved', 'sent', 'confirmed'], true)) $po->update(['approved_by' => Auth::id(), 'approved_at' => now()]);
            return $po->fresh(['items.product', 'supplier']);
        });
    }

    public function confirmPurchaseOrder(int $id, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $data) {
            $po = PurchaseOrder::query()->where('business_id', AppController::businessId())->findOrFail($id);
            SupplierConfirmation::query()->create(['business_id' => $po->business_id, 'purchase_order_id' => $po->id, 'confirmation_status' => $data['confirmation_status'] ?? 'accepted', 'expected_delivery_date' => $data['expected_delivery_date'] ?? $po->expected_delivery_date, 'modified_items_json' => $data['items'] ?? null, 'remarks' => $data['remarks'] ?? null, 'created_by' => Auth::id()]);
            $po->update(['confirmation_status' => $data['confirmation_status'] ?? 'accepted', 'status' => ($data['confirmation_status'] ?? 'accepted') === 'accepted' ? 'confirmed' : 'sent']);
            return $po->fresh('items');
        });
    }

    public function goodsReceipts(array $filters) { return $this->applyDocumentFilters(GoodsReceipt::query()->with(['supplier', 'order', 'warehouse', 'items.product'])->where('business_id', AppController::businessId()), $filters, 'grn_number', 'status', 'receipt_date', 'supplier_id')->latest('id')->paginate(20); }

    public function saveGoodsReceipt(array $data, ?int $id = null): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $this->supplier($data['supplier_id']); $this->warehouse($data['warehouse_id'], $data['branch_id'] ?? null);
            $items = $data['items']; unset($data['items']);
            $grn = $id ? GoodsReceipt::query()->where('business_id', $businessId)->findOrFail($id) : new GoodsReceipt(['business_id' => $businessId, 'grn_number' => $this->nextNumber('GRN', GoodsReceipt::class, 'grn_number'), 'created_by' => Auth::id()]);
            if (in_array($grn->status, ['received', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'Received GRN cannot be edited.']);
            $grn->fill($data)->save();
            $grn->items()->delete(); $grn->items()->createMany($items);
            if ($data['status'] === 'received') $this->receiveGoods($grn->id);
            return $grn->fresh(['items.product', 'supplier']);
        });
    }

    public function receiveGoods(int $id): GoodsReceipt
    {
        return DB::transaction(function () use ($id) {
            $grn = GoodsReceipt::query()->where('business_id', AppController::businessId())->with(['items', 'order.items'])->findOrFail($id);
            if ($this->stockPosted(GoodsReceipt::class, $grn->id)) return $grn;
            foreach ($grn->items as $item) {
                $net = max(0, (float) $item->received_quantity - (float) $item->rejected_quantity - (float) $item->damaged_quantity);
                if ($item->purchase_order_item_id && $grn->order) {
                    $poItem = $grn->order->items->firstWhere('id', $item->purchase_order_item_id);
                    if ($poItem && $net > ((float) $poItem->ordered_quantity - (float) $poItem->received_quantity)) throw ValidationException::withMessages(['received_quantity' => 'Cannot over-receive purchase order item.']);
                }
                if ($net > 0) $this->stock->increaseStock(['business_id' => $grn->business_id, 'branch_id' => $grn->branch_id, 'warehouse_id' => $grn->warehouse_id, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id, 'batch_id' => $item->batch_id, 'serial_id' => $item->serial_id, 'transaction_type' => 'goods_receipt', 'reference_type' => GoodsReceipt::class, 'reference_id' => $grn->id, 'quantity' => $net, 'unit_cost' => (float) $item->unit_cost, 'warehouse_location' => $item->warehouse_location, 'transaction_date' => $grn->receipt_date, 'remarks' => 'GRN ' . $grn->grn_number]);
                if ($item->purchase_order_item_id) $item->orderItem()->increment('received_quantity', $net);
            }
            if ($grn->order) $this->refreshPurchaseOrderReceipt($grn->order);
            $grn->update(['status' => 'received', 'received_by' => Auth::id(), 'received_at' => now()]);
            return $grn->fresh(['items.product']);
        });
    }

    public function dashboard(): array
    {
        $businessId = AppController::businessId();
        return [
            'pending_quotations' => Quotation::query()->where('business_id', $businessId)->whereIn('status', ['draft', 'sent', 'viewed'])->count(),
            'quotation_value' => (float) Quotation::query()->where('business_id', $businessId)->whereIn('status', ['sent', 'accepted'])->sum('grand_total'),
            'pending_sales_orders' => SalesOrder::query()->where('business_id', $businessId)->whereIn('order_status', ['draft', 'pending_approval', 'approved', 'processing'])->count(),
            'pending_purchase_orders' => PurchaseOrder::query()->where('business_id', $businessId)->whereIn('status', ['draft', 'approved', 'sent', 'confirmed'])->count(),
            'pending_dispatch' => DeliveryChallan::query()->where('business_id', $businessId)->where('status', 'draft')->count(),
            'pending_goods_receipt' => GoodsReceipt::query()->where('business_id', $businessId)->where('status', 'draft')->count(),
            'pending_supplier_confirmation' => PurchaseOrder::query()->where('business_id', $businessId)->where('confirmation_status', 'pending')->count(),
            'pending_back_orders' => BackOrder::query()->where('business_id', $businessId)->where('status', 'open')->count(),
        ];
    }

    public function reports(): array
    {
        $businessId = AppController::businessId();
        $quotes = Quotation::query()->where('business_id', $businessId);
        $converted = (clone $quotes)->where('status', 'converted')->count();
        $totalQuotes = (clone $quotes)->count();
        $orders = SalesOrder::query()->where('business_id', $businessId);
        $delivered = (clone $orders)->whereIn('order_status', ['delivered', 'closed'])->count();
        $totalOrders = (clone $orders)->count();
        return [
            'quotation_report' => (clone $quotes)->latest('id')->limit(100)->get(),
            'sales_order_report' => (clone $orders)->latest('id')->limit(100)->get(),
            'purchase_order_report' => PurchaseOrder::query()->where('business_id', $businessId)->latest('id')->limit(100)->get(),
            'back_order_report' => BackOrder::query()->where('business_id', $businessId)->latest('id')->limit(100)->get(),
            'quotation_conversion_percent' => $totalQuotes ? round($converted / $totalQuotes * 100, 2) : 0,
            'order_fulfillment_percent' => $totalOrders ? round($delivered / $totalOrders * 100, 2) : 0,
        ];
    }

    private function calculateItems(array $rows, string $type): array
    {
        return collect($rows)->map(function ($row) use ($type) {
            $product = $this->product($row['product_id']);
            $qty = (float) ($row['quantity'] ?? $row['ordered_quantity'] ?? 0);
            $rate = (float) ($row['unit_price'] ?? $row['purchase_rate'] ?? 0);
            $discount = (float) ($row['discount'] ?? $row['discount_amount'] ?? 0);
            $taxable = max(0, round($qty * $rate - $discount, 2));
            $gstRate = (float) ($row['gst_rate'] ?? $product->gst_rate ?? 0);
            $tax = round($taxable * $gstRate / 100, 2);
            $base = ['product_id' => $product->id, 'unit_id' => $row['unit_id'] ?? $product->unit_id ?? null, 'tax_snapshot' => ['gst_rate' => $gstRate], 'tax_amount' => $tax, 'line_total' => round($taxable + $tax, 2)];
            if ($type === 'purchase') return array_merge($row, $base, ['ordered_quantity' => $qty, 'purchase_rate' => $rate]);
            if ($type === 'sales_order') return array_merge($row, $base, ['ordered_quantity' => $qty, 'unit_price' => $rate, 'discount_amount' => $discount]);
            return ['product_id' => $product->id, 'variant_id' => $row['variant_id'] ?? null, 'batch_id' => $row['batch_id'] ?? null, 'description' => $row['description'] ?? $product->name, 'quantity' => $qty, 'unit_id' => $row['unit_id'] ?? $product->unit_id ?? null, 'unit_price' => $rate, 'discount' => $discount, 'taxable_amount' => $taxable, 'gst_snapshot' => ['gst_rate' => $gstRate, 'tax_amount' => $tax], 'total' => round($taxable + $tax, 2)];
        })->all();
    }

    private function totals(array $items, ?string $discountType, float $discountValue, float $shipping): array
    {
        $subtotal = collect($items)->sum(fn ($i) => (float) ($i['taxable_amount'] ?? ((float) ($i['ordered_quantity'] ?? 0) * (float) ($i['unit_price'] ?? $i['purchase_rate'] ?? 0))));
        $tax = collect($items)->sum(fn ($i) => (float) ($i['tax_amount'] ?? ($i['gst_snapshot']['tax_amount'] ?? 0)));
        $discount = $discountType === 'percentage' ? round($subtotal * min($discountValue, 100) / 100, 2) : min($subtotal, $discountValue);
        $grand = round($subtotal - $discount + $tax + $shipping, 2);
        return ['subtotal' => round($subtotal, 2), 'discount_amount' => round($discount, 2), 'taxable_amount' => round(max(0, $subtotal - $discount), 2), 'cgst' => round($tax / 2, 2), 'sgst' => round($tax / 2, 2), 'igst' => 0, 'cess' => 0, 'round_off' => round(round($grand) - $grand, 2), 'grand_total' => round($grand + (round($grand) - $grand), 2)];
    }

    private function refreshSalesOrderFulfillment(SalesOrder $order): void
    {
        $order->load('items');
        $total = $order->items->sum('ordered_quantity'); $delivered = $order->items->sum('delivered_quantity');
        $order->update(['dispatch_status' => $delivered <= 0 ? 'pending' : ($delivered >= $total ? 'completed' : 'partial'), 'order_status' => $delivered >= $total ? 'delivered' : 'partially_delivered']);
    }

    private function refreshPurchaseOrderReceipt(PurchaseOrder $po): void
    {
        $po->load('items');
        $total = $po->items->sum('ordered_quantity'); $received = $po->items->sum('received_quantity');
        $po->update(['receipt_status' => $received <= 0 ? 'not_received' : ($received >= $total ? 'received' : 'partial_received'), 'status' => $received >= $total ? 'received' : 'partial_received']);
    }

    private function stockPosted(string $type, int $id): bool
    {
        return DB::table('stock_ledgers')->where('business_id', AppController::businessId())->where('reference_type', $type)->where('reference_id', $id)->exists();
    }

    private function productQuery(): Builder
    {
        $businessId = AppController::businessId();
        return Product::query()->where(function (Builder $q) use ($businessId) { $q->where('business_id', $businessId)->orWhere('company_id', $businessId); })->where('status', 'active');
    }
    private function customer(int $id): Customer { return Customer::query()->where('business_id', AppController::businessId())->where('status', 'active')->findOrFail($id); }
    private function supplier(int $id): Supplier { return Supplier::query()->where('business_id', AppController::businessId())->where('status', 'active')->findOrFail($id); }
    private function branch(int $id): Branch { return Branch::query()->where('business_id', AppController::businessId())->findOrFail($id); }
    private function warehouse(int $id, ?int $branchId = null): Warehouse { return Warehouse::query()->where('business_id', AppController::businessId())->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))->findOrFail($id); }
    private function product(int $id): Product { return $this->productQuery()->findOrFail($id); }
    private function nextNumber(string $prefix, string $model, string $column): string { $prefix .= '-' . date('Y') . '-'; $last = $model::query()->where('business_id', AppController::businessId())->where($column, 'like', $prefix . '%')->orderByDesc('id')->value($column); return $prefix . str_pad((string) ($last ? ((int) substr($last, strlen($prefix)) + 1) : 1), 5, '0', STR_PAD_LEFT); }
    private function history(string $type, int $id, ?string $old, string $new, ?string $remarks = null): void { OrderStatusHistory::query()->create(['business_id' => AppController::businessId(), 'document_type' => $type, 'document_id' => $id, 'old_status' => $old, 'new_status' => $new, 'remarks' => $remarks, 'created_by' => Auth::id()]); }
    private function notification(string $type, int $id, string $event, array $payload): void { OrderNotification::query()->create(['business_id' => AppController::businessId(), 'document_type' => $type, 'document_id' => $id, 'event_name' => $event, 'channel' => 'in_app', 'payload_json' => $payload]); }

    private function applyDocumentFilters(Builder $query, array $filters, string $numberColumn, string $statusColumn, string $dateColumn, ?string $partyColumn = null): Builder
    {
        return $query
            ->when($filters['q'] ?? null, function (Builder $builder, string $q) use ($numberColumn) {
                $builder->where(function (Builder $search) use ($numberColumn, $q) {
                    $search->where($numberColumn, 'like', '%' . $q . '%')
                        ->orWhereHas('items.product', fn (Builder $product) => $product->where('name', 'like', '%' . $q . '%')->orWhere('sku', 'like', '%' . $q . '%'));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where($statusColumn, $status))
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $branchId) => $builder->where('branch_id', $branchId))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $builder, int $warehouseId) => $builder->where('warehouse_id', $warehouseId))
            ->when($partyColumn && ($filters[$partyColumn] ?? null), fn (Builder $builder, int $partyId) => $builder->where($partyColumn, $partyId))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate($dateColumn, '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date) => $builder->whereDate($dateColumn, '<=', $date));
    }
}
