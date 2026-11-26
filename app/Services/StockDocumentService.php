<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\DeliveryChallan;
use App\Models\GoodsReceipt;
use App\Models\PurchaseVoucher;
use App\Models\SalesVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Goods move once. A goods receipt (purchase side) or delivery challan (sales side) moves stock before the bill.
 * When that bill is posted, the document's stock entry is taken back and the bill's entry stands in its place;
 * when the bill is reversed, the document's entry returns. Posting goods that look already moved asks first.
 */
class StockDocumentService
{
    /** Warnings look this many days around the bill date; older unbilled documents are left alone. */
    private const NEAR_DAYS = 30;
    private const SAME_DELIVERY_DAYS = 3;
    /** Challans given away are never billed. */
    private const NEVER_BILLED = '/\b(sample|gift|free|self)\b/i';

    public function __construct(private StockService $stock) {}

    public static function ready(): bool
    {
        return Schema::hasColumn('goods_receipts', 'billed_voucher_id') && Schema::hasColumn('delivery_challans', 'billed_voucher_id');
    }

    /* ---------- linking ---------- */

    /** A received goods receipt (or sent challan) of this business that no bill has used yet. */
    public function billable(string $class, int $id): Model
    {
        if (!self::ready()) {
            throw ValidationException::withMessages(['document' => 'Run the database update to bill goods receipts and challans.']);
        }
        $document = $class::query()->where('business_id', AppController::businessId())->with('items.product')->lockForUpdate()->findOrFail($id);
        $moved = $class === GoodsReceipt::class ? ['received'] : ['dispatched', 'delivered'];

        if (!in_array($document->status, $moved, true)) {
            throw ValidationException::withMessages(['document' => $class === GoodsReceipt::class ? 'Receive the goods first.' : 'Send the goods (reduce stock) first.']);
        }
        if ($document->billed_voucher_id) {
            $bill = $this->billModel($class)::query()->find($document->billed_voucher_id);
            throw ValidationException::withMessages(['document' => 'A bill already exists for this document: ' . ($bill->invoice_number ?? $bill->voucher_number ?? '#' . $document->billed_voucher_id) . '.']);
        }

        return $document;
    }

    /** Ties a new bill to the document. Call before the bill is posted. */
    public function attach(Model $bill, string $class, int $documentId): void
    {
        $document = $this->billable($class, $documentId);
        $party = $class === GoodsReceipt::class ? 'supplier_id' : 'customer_id';

        if ((int) $document->$party !== (int) $bill->$party) {
            throw ValidationException::withMessages([$party => 'The bill must be for the same party as the document it is made from.']);
        }
        if ((int) $document->warehouse_id !== (int) $bill->warehouse_id) {
            throw ValidationException::withMessages(['warehouse_id' => 'The bill must use the same warehouse as the document it is made from.']);
        }
        $document->forceFill(['billed_voucher_id' => $bill->id])->save();
    }

    /** An edited bill replaces its row; the documents follow it. */
    public function move(string $class, int $from, int $to): void
    {
        if (self::ready()) {
            $class::query()->where('billed_voucher_id', $from)->update(['billed_voucher_id' => $to]);
        }
    }

    /** A cancelled draft frees its documents. Their stock was never touched. */
    public function unlink(string $class, int $billId): void
    {
        $this->move($class, $billId, 0);
        if (self::ready()) {
            $class::query()->where('billed_voucher_id', 0)->update(['billed_voucher_id' => null]);
        }
    }

    /* ---------- posting and reversing ---------- */

    /** Checks run before a bill moves stock. */
    public function beforePosting(Model $bill): void
    {
        if (!self::ready()) {
            return;
        }
        $class = $this->documentClass($bill);
        $documents = $this->linked($class, $bill);

        if ($documents->isNotEmpty()) {
            $this->confirmSameQuantities($bill, $documents);
        } else {
            $this->confirmNotAlreadyMoved($bill, $class);
        }
    }

    /** The bill's stock now stands in place of the documents'. */
    public function release(Model $bill): void
    {
        if (!self::ready()) {
            return;
        }
        $class = $this->documentClass($bill);

        foreach ($this->linked($class, $bill) as $document) {
            foreach ($this->held($class, $document) as $row) {
                $net = round((float) $row->net, 3);
                $entry = $this->entry($document, (array) $row, abs($net), ($class === GoodsReceipt::class ? 'goods_receipt' : 'delivery_challan') . '_reversal', $class, 'Billed on ' . ($bill->invoice_number ?? $bill->voucher_number));
                $net > 0 ? $this->stock->decreaseStock($entry + ['allow_shortfall' => true]) : $this->stock->increaseStock($entry);
            }
        }
    }

    /** The bill was reversed: the goods did move, so the documents hold the stock again and can be billed again. */
    public function restore(Model $bill): void
    {
        if (!self::ready()) {
            return;
        }
        $class = $this->documentClass($bill);

        foreach ($this->linked($class, $bill) as $document) {
            if ($this->held($class, $document)->isEmpty()) {
                foreach ($document->items as $item) {
                    $quantity = $this->documentQuantity($class, $item);
                    if ($quantity <= 0) {
                        continue;
                    }
                    $entry = $this->entry($document, $item->only(['product_id', 'product_variant_id', 'batch_id', 'serial_id', 'unit_cost']), $quantity, $class === GoodsReceipt::class ? 'goods_receipt' : 'delivery_challan', $class, 'Bill reversed');
                    $class === GoodsReceipt::class ? $this->stock->increaseStock($entry) : $this->stock->decreaseStock($entry + ['allow_shortfall' => true]);
                }
            }
            $document->forceFill(['billed_voucher_id' => null])->save();
        }
    }

    /** Receiving goods or sending a challan after the bill already moved them. */
    public function confirmDocumentNotBilled(Model $document): void
    {
        if (!self::ready() || $document->billed_voucher_id) {
            return;
        }
        $purchase = $document instanceof GoodsReceipt;
        $party = $purchase ? 'supplier_id' : 'customer_id';
        if (!$purchase && ($this->neverBilled($document) || $this->isWalkIn((int) $document->customer_id))) {
            return;
        }
        $date = $document->{$purchase ? 'receipt_date' : 'challan_date'};
        $dateColumn = $purchase ? 'purchase_date' : 'invoice_date';
        $bills = $this->billModel(get_class($document))::query()->where('business_id', $document->business_id)->where($party, $document->$party)->whereIn('status', ['approved', 'confirmed']);

        if ($purchase && filled($document->supplier_invoice_number) && ($same = (clone $bills)->where('supplier_invoice_number', $document->supplier_invoice_number)->value('voucher_number'))) {
            throw ValidationException::withMessages(['supplier_invoice_number' => "Supplier bill {$document->supplier_invoice_number} is already entered as {$same}, which added the stock. Do not receive these goods again."]);
        }

        $products = $document->items->pluck('product_id')->all();
        $match = $bills->whereBetween($dateColumn, [$date->copy()->subDays(self::SAME_DELIVERY_DAYS)->toDateString(), $date->copy()->addDays(self::SAME_DELIVERY_DAYS)->toDateString()])
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $products))->first();

        if ($match) {
            $this->ask(($purchase ? 'Purchase ' . $match->voucher_number . ' of this supplier' : 'Invoice ' . $match->invoice_number . ' of this customer') . ' already has some of these items and has moved their stock. Continue only if these are different goods.');
        }
    }

    /* ---------- helpers ---------- */

    private function linked(string $class, Model $bill)
    {
        return $class::query()->where('business_id', $bill->business_id)->where('billed_voucher_id', $bill->id)->with('items.product')->get();
    }

    private function confirmSameQuantities(Model $bill, $documents): void
    {
        $class = get_class($documents->first());
        $add = function (array &$totals, $item, float $quantity) {
            $key = $item->product_id . ':' . (int) $item->product_variant_id;
            $totals[$key] = ['name' => $item->product->name ?? 'Item', 'qty' => ($totals[$key]['qty'] ?? 0) + $quantity];
        };
        $moved = $billed = [];
        foreach ($documents as $document) {
            foreach ($document->items as $item) {
                $add($moved, $item, $this->documentQuantity($class, $item));
            }
        }
        foreach ($bill->items()->with('product')->get() as $item) {
            // Services carry no stock, so they cannot differ.
            if (($item->product->product_type ?? '') === 'service' || ($item->product->item_type ?? '') === 'non_stock') {
                continue;
            }
            $add($billed, $item, (float) $item->quantity + (float) $item->free_quantity);
        }

        $differences = [];
        foreach ($moved + $billed as $key => $row) {
            $a = $moved[$key]['qty'] ?? 0;
            $b = $billed[$key]['qty'] ?? 0;
            if (abs($a - $b) > 0.0005) {
                $differences[] = "{$row['name']}: " . ($class === GoodsReceipt::class ? 'received' : 'sent') . ' ' . round($a, 3) . ', billed ' . round($b, 3);
            }
        }
        if ($differences) {
            $this->ask('The bill differs from the ' . ($class === GoodsReceipt::class ? 'goods receipt' : 'challan') . '. ' . implode('; ', array_slice($differences, 0, 5)) . '. Stock will follow the bill. Continue?');
        }
    }

    private function confirmNotAlreadyMoved(Model $bill, string $class): void
    {
        $purchase = $class === GoodsReceipt::class;
        $party = $purchase ? 'supplier_id' : 'customer_id';
        if (!$bill->$party || (!$purchase && $this->isWalkIn((int) $bill->$party))) {
            return;
        }
        $date = $bill->{$purchase ? 'purchase_date' : 'invoice_date'};
        $column = $purchase ? 'receipt_date' : 'challan_date';
        $products = $bill->items()->pluck('product_id')->all();

        $open = $class::query()->where('business_id', $bill->business_id)->where($party, $bill->$party)->whereNull('billed_voucher_id')
            ->whereIn('status', $purchase ? ['received'] : ['dispatched', 'delivered'])
            ->whereBetween($column, [$date->copy()->subDays(self::NEAR_DAYS)->toDateString(), $date->copy()->addDays(self::NEAR_DAYS)->toDateString()])
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $products))->get()
            ->reject(fn ($document) => !$purchase && $this->neverBilled($document));

        if ($open->isNotEmpty()) {
            $numbers = $open->take(3)->map(fn ($d) => $purchase ? $d->grn_number : $d->challan_number)->implode(', ');
            $this->ask($purchase
                ? "Some of these items were already received on {$numbers}, which added their stock. To bill those goods, cancel and use Stock Inward / GRN > Create Purchase Bill. Continue only if these are different goods."
                : "Some of these items were already sent on challan {$numbers}, which reduced their stock. To bill those goods, cancel and use Sales Orders > Challans > Create Bill. Continue only if these are different goods.");
        }
    }

    /** Refuses until the person confirms; the screen resends with confirm_goods. Only for requests from a screen. */
    private function ask(string $message): void
    {
        if (request()->route() && !request()->boolean('confirm_goods')) {
            throw ValidationException::withMessages(['confirm_goods' => $message]);
        }
    }

    private function held(string $class, Model $document)
    {
        return DB::table('stock_ledgers')->where('business_id', $document->business_id)->where('reference_type', $class)->where('reference_id', $document->id)
            ->groupBy('product_id', 'product_variant_id', 'batch_id', 'serial_id', 'stock_status')
            ->selectRaw('product_id, product_variant_id, batch_id, serial_id, stock_status, SUM(quantity_in) - SUM(quantity_out) as net, MAX(unit_cost) as unit_cost')
            ->havingRaw('ABS(SUM(quantity_in) - SUM(quantity_out)) > 0.0005')->get();
    }

    private function entry(Model $document, array $row, float $quantity, string $type, string $class, string $remarks): array
    {
        return [
            'business_id' => $document->business_id, 'branch_id' => $document->branch_id, 'warehouse_id' => $document->warehouse_id,
            'product_id' => $row['product_id'], 'product_variant_id' => $row['product_variant_id'] ?? null, 'batch_id' => $row['batch_id'] ?? null,
            'serial_id' => $row['serial_id'] ?? null, 'stock_status' => $row['stock_status'] ?? 'saleable',
            'transaction_type' => $type, 'reference_type' => $class, 'reference_id' => $document->id,
            'quantity' => $quantity, 'unit_cost' => (float) ($row['unit_cost'] ?? 0),
            // Dated as the document, so stock on any past date stays right.
            'transaction_date' => $class === GoodsReceipt::class ? $document->receipt_date : $document->challan_date,
            'remarks' => $remarks . ' (' . ($document->grn_number ?? $document->challan_number) . ')',
        ];
    }

    private function documentQuantity(string $class, $item): float
    {
        return $class === GoodsReceipt::class
            ? max(0, (float) $item->received_quantity - (float) $item->rejected_quantity - (float) $item->damaged_quantity)
            : (float) $item->dispatch_quantity;
    }

    private function documentClass(Model $bill): string
    {
        return $bill instanceof PurchaseVoucher ? GoodsReceipt::class : DeliveryChallan::class;
    }

    private function billModel(string $class): string
    {
        return $class === GoodsReceipt::class ? PurchaseVoucher::class : SalesVoucher::class;
    }

    private function neverBilled(Model $challan): bool
    {
        return (bool) preg_match(self::NEVER_BILLED, (string) $challan->remarks);
    }

    private function isWalkIn(int $customerId): bool
    {
        return DB::table('customers')->where('id', $customerId)->value('customer_type') === 'walk_in';
    }
}
