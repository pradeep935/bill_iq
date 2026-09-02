<?php

namespace App\Services;

use App\Models\StockLedger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InventoryMovementService
{
    public function normalizeCollection(Collection $ledger, array $referenceNumbers = []): Collection
    {
        $used = [];
        $rows = collect();

        foreach ($ledger->values() as $index => $entry) {
            if (isset($used[$index])) {
                continue;
            }

            if ($this->isReclassification($entry)) {
                $pairIndex = $this->findReclassificationPair($ledger, $entry, $index, $used);
                if ($pairIndex !== null) {
                    $used[$index] = true;
                    $used[$pairIndex] = true;
                    $rows->push($this->normalizeReclassificationPair($entry, $ledger->values()->get($pairIndex), $referenceNumbers));
                    continue;
                }
            }

            $used[$index] = true;
            $rows->push($this->normalizeLedger($entry, $referenceNumbers));
        }

        return $rows->values();
    }

    public function normalizeLedger(StockLedger $entry, array $referenceNumbers = []): array
    {
        $qtyIn = max((float) ($entry->quantity_in ?? 0), 0);
        $qtyOut = max((float) ($entry->quantity_out ?? 0), 0);
        $net = round($qtyIn - $qtyOut, 3);
        $movementQty = round(max(abs($qtyIn), abs($qtyOut), abs($net)), 3);
        $referenceNumber = $this->referenceNumber($entry, $referenceNumbers);
        $fromLocation = $qtyOut > 0 ? ($entry->warehouse_location ?: null) : null;
        $toLocation = $qtyIn > 0 ? ($entry->warehouse_location ?: null) : null;
        $condition = $this->conditionLabel($entry->stock_status);

        return [
            'id' => $entry->id,
            'ledger_id' => $entry->id,
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
            'reference_number' => $referenceNumber,
            'date_time' => optional($entry->posted_at ?: $entry->created_at)->toISOString(),
            'posted_at' => optional($entry->posted_at)->toISOString(),
            'created_at' => optional($entry->created_at)->toISOString(),
            'document_date' => optional($entry->transaction_date)->toDateString(),
            'transaction_date' => optional($entry->transaction_date)->toDateString(),
            'source_module' => $this->sourceModule($entry),
            'movement_type' => $this->movementLabel($entry),
            'movement_code' => $entry->transaction_type,
            'product' => $entry->product?->name,
            'product_name' => $entry->product?->name,
            'sku' => $entry->product?->sku,
            'barcode' => $entry->product?->primary_barcode ?: $entry->product?->barcode,
            'branch' => $entry->branch?->name,
            'warehouse' => $entry->warehouse?->name,
            'from_location' => $fromLocation,
            'to_location' => $toLocation,
            'warehouse_location' => $entry->warehouse_location,
            'from_condition' => $qtyOut > 0 ? $condition : null,
            'to_condition' => $qtyIn > 0 ? $condition : null,
            'stock_condition' => $condition,
            'qty_in' => round($qtyIn, 3),
            'qty_out' => round($qtyOut, 3),
            'movement_qty' => $movementQty,
            'net_quantity' => $net,
            'physical_impact' => $net,
            'rate' => round((float) ($entry->unit_cost ?? 0), 2),
            'movement_value' => round(abs((float) ($entry->stock_value ?? 0)), 2),
            'uom' => $entry->product?->unit?->name ?? $entry->product?->unit_name ?? $entry->product?->unit_code ?? null,
            'reason' => $this->reason($entry),
            'remarks' => $entry->remarks,
            'user' => $entry->creator?->name ?: ($entry->created_by ? 'User' : 'System'),
            'posting_status' => 'Posted',
            'impact_summary' => $this->impactSummary($movementQty, $net, $condition, $entry),
        ];
    }

    public function movementLabel(StockLedger $entry): string
    {
        if ($entry->reference_type && Str::endsWith($entry->reference_type, 'StockCountSession')) {
            return (float) ($entry->quantity_in ?? 0) > 0 ? 'Physical Count Gain' : 'Physical Count Shortage';
        }

        return [
            'opening_stock' => 'Opening Stock',
            'opening_stock_reversal' => 'Opening Stock Reversal',
            'purchase' => 'Purchase',
            'goods_receipt' => 'Goods Receipt',
            'sale' => 'Sales Issue',
            'delivery_challan' => 'Delivery Challan',
            'sales_return' => 'Sales Return',
            'purchase_return' => 'Purchase Return',
            'stock_adjustment_in' => 'Stock Adjustment In',
            'stock_adjustment_out' => 'Stock Adjustment Out',
            'damaged_stock' => 'Damaged Stock Write-off',
            'expired_stock' => 'Expired Stock Write-off',
            'lost_stock' => 'Lost Stock Write-off',
            'defective_stock' => 'Defective Stock Write-off',
            'stock_reclassification_in' => 'Stock Reclassification',
            'stock_reclassification_out' => 'Stock Reclassification',
            'stock_transfer_in' => 'Stock Transfer In',
            'stock_transfer_out' => 'Stock Transfer Out',
            'location_transfer' => 'Location Movement',
            'physical_count_gain' => 'Physical Count Gain',
            'physical_count_shortage' => 'Physical Count Shortage',
            'production_consumption' => 'Production Consumption',
            'production_output' => 'Production Output',
            'manufacturing_consumption' => 'Manufacturing Consumption',
            'manufacturing_production' => 'Manufacturing Production',
            'batch_adjustment' => 'Batch Adjustment',
            'serial_movement' => 'Serial Movement',
        ][$entry->transaction_type] ?? Str::of($entry->transaction_type ?: 'stock_movement')->replace('_', ' ')->title()->toString();
    }

    public function sourceModule(StockLedger $entry): string
    {
        $class = class_basename((string) $entry->reference_type);

        return [
            'OpeningStockVoucher' => 'Opening Stock',
            'PurchaseVoucher' => 'Purchase',
            'GoodsReceipt' => 'Purchase / GRN',
            'SalesVoucher' => 'Sales',
            'DeliveryChallan' => 'Sales',
            'SalesReturnVoucher' => 'Sales Return',
            'PurchaseReturnVoucher' => 'Purchase Return',
            'StockAdjustmentVoucher' => 'Inventory Adjustment',
            'StockTransferVoucher' => 'Stock Transfer',
            'StockCountSession' => 'Physical Count',
            'LocationTransferVoucher' => 'Location Movement',
            'ProductionOrder' => 'Manufacturing',
        ][$class] ?? $this->movementLabel($entry);
    }

    private function normalizeReclassificationPair(StockLedger $first, StockLedger $second, array $referenceNumbers): array
    {
        $out = (float) ($first->quantity_out ?? 0) > 0 ? $first : $second;
        $in = $out === $first ? $second : $first;
        $quantity = round(max((float) $out->quantity_out, (float) $in->quantity_in), 3);
        $row = $this->normalizeLedger($in, $referenceNumbers);
        $from = $this->conditionLabel($out->stock_status);
        $to = $this->conditionLabel($in->stock_status);

        return array_merge($row, [
            'id' => 'reclass-' . $out->id . '-' . $in->id,
            'ledger_id' => $in->id,
            'paired_ledger_ids' => [$out->id, $in->id],
            'date_time' => optional(($in->posted_at ?: $out->posted_at) ?: ($in->created_at ?: $out->created_at))->toISOString(),
            'movement_type' => Str::lower($in->stock_status ?: '') === 'saleable' ? 'Recovered / Repaired Stock' : 'Stock Reclassification',
            'movement_code' => 'stock_reclassification',
            'from_location' => $out->warehouse_location ?: null,
            'to_location' => $in->warehouse_location ?: null,
            'from_condition' => $from,
            'to_condition' => $to,
            'stock_condition' => $to,
            'qty_in' => 0,
            'qty_out' => 0,
            'movement_qty' => $quantity,
            'net_quantity' => 0,
            'physical_impact' => 0,
            'movement_value' => round(abs((float) ($out->stock_value ?: $in->stock_value ?: ($quantity * (float) $in->unit_cost))), 2),
            'impact_summary' => $quantity . ' units moved from ' . $from . ' to ' . $to . '; physical stock unchanged.',
        ]);
    }

    private function findReclassificationPair(Collection $ledger, StockLedger $entry, int $index, array $used): ?int
    {
        $values = $ledger->values();

        foreach ($values as $candidateIndex => $candidate) {
            if ($candidateIndex === $index || isset($used[$candidateIndex]) || !$this->isReclassification($candidate)) {
                continue;
            }

            if ($entry->reference_type === $candidate->reference_type
                && (int) $entry->reference_id === (int) $candidate->reference_id
                && (int) $entry->product_id === (int) $candidate->product_id
                && (int) ($entry->product_variant_id ?? 0) === (int) ($candidate->product_variant_id ?? 0)
                && (int) ($entry->batch_id ?? 0) === (int) ($candidate->batch_id ?? 0)
                && (int) ($entry->warehouse_id ?? 0) === (int) ($candidate->warehouse_id ?? 0)
                && (float) ($entry->quantity_in ?? 0) !== (float) ($candidate->quantity_in ?? 0)) {
                return $candidateIndex;
            }
        }

        return null;
    }

    private function isReclassification(StockLedger $entry): bool
    {
        return in_array($entry->transaction_type, ['stock_reclassification_in', 'stock_reclassification_out'], true);
    }

    private function referenceNumber(StockLedger $entry, array $referenceNumbers): string
    {
        return $referenceNumbers[$entry->reference_type . ':' . $entry->reference_id] ?? (string) ($entry->reference_id ?: 'Ledger #' . $entry->id);
    }

    private function conditionLabel(?string $value): string
    {
        return Str::of($value ?: 'saleable')->replace('_', ' ')->title()->toString();
    }

    private function reason(StockLedger $entry): ?string
    {
        $remarks = trim((string) $entry->remarks);
        if ($remarks === '') {
            return null;
        }

        return $remarks;
    }

    private function impactSummary(float $movementQty, float $net, string $condition, StockLedger $entry): string
    {
        if ($entry->transaction_type === 'stock_transfer_out') {
            return $movementQty . ' units transferred out; physical stock reduced at this warehouse.';
        }

        if ($entry->transaction_type === 'stock_transfer_in') {
            return $movementQty . ' units transferred in; physical stock increased at this warehouse.';
        }

        if ($net > 0) {
            return '+' . $movementQty . ' units added to ' . $condition . ' stock.';
        }

        if ($net < 0) {
            return $movementQty . ' units removed from ' . $condition . ' stock.';
        }

        return 'No physical stock change.';
    }
}
