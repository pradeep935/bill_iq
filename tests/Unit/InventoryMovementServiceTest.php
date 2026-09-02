<?php

namespace Tests\Unit;

use App\Models\StockLedger;
use App\Services\InventoryMovementService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InventoryMovementServiceTest extends TestCase
{
    public function test_stock_out_uses_quantity_out_and_signed_net(): void
    {
        $entry = new StockLedger([
            'id' => 10,
            'transaction_type' => 'sale',
            'reference_type' => 'App\\Models\\SalesVoucher',
            'reference_id' => 5,
            'quantity_in' => 0,
            'quantity_out' => 7,
            'unit_cost' => 40,
            'stock_value' => -280,
            'stock_status' => 'saleable',
        ]);

        $row = (new InventoryMovementService())->normalizeLedger($entry, ['App\\Models\\SalesVoucher:5' => 'SAL-5']);

        $this->assertSame(0.0, $row['qty_in']);
        $this->assertSame(7.0, $row['qty_out']);
        $this->assertSame(-7.0, $row['net_quantity']);
        $this->assertSame(7.0, $row['movement_qty']);
        $this->assertSame('Sales Issue', $row['movement_type']);
    }

    public function test_opening_stock_reversal_keeps_out_quantity(): void
    {
        $entry = new StockLedger([
            'id' => 11,
            'transaction_type' => 'opening_stock_reversal',
            'quantity_in' => 0,
            'quantity_out' => 3,
            'unit_cost' => 25,
            'stock_value' => -75,
            'stock_status' => 'saleable',
        ]);

        $row = (new InventoryMovementService())->normalizeLedger($entry);

        $this->assertSame(3.0, $row['qty_out']);
        $this->assertSame(-3.0, $row['physical_impact']);
        $this->assertSame('Opening Stock Reversal', $row['movement_type']);
    }

    public function test_reclassification_pair_has_moved_quantity_and_zero_physical_impact(): void
    {
        $out = new StockLedger([
            'id' => 21,
            'transaction_type' => 'stock_reclassification_out',
            'reference_type' => 'App\\Models\\StockAdjustmentVoucher',
            'reference_id' => 8,
            'product_id' => 2,
            'warehouse_id' => 3,
            'quantity_in' => 0,
            'quantity_out' => 4,
            'stock_status' => 'damaged',
            'unit_cost' => 10,
        ]);
        $in = new StockLedger([
            'id' => 22,
            'transaction_type' => 'stock_reclassification_in',
            'reference_type' => 'App\\Models\\StockAdjustmentVoucher',
            'reference_id' => 8,
            'product_id' => 2,
            'warehouse_id' => 3,
            'quantity_in' => 4,
            'quantity_out' => 0,
            'stock_status' => 'saleable',
            'unit_cost' => 10,
        ]);

        $rows = (new InventoryMovementService())->normalizeCollection(new Collection([$out, $in]));

        $this->assertCount(1, $rows);
        $this->assertSame(4.0, $rows[0]['movement_qty']);
        $this->assertSame(0, $rows[0]['qty_in']);
        $this->assertSame(0, $rows[0]['qty_out']);
        $this->assertSame(0, $rows[0]['physical_impact']);
        $this->assertSame('Damaged', $rows[0]['from_condition']);
        $this->assertSame('Saleable', $rows[0]['to_condition']);
    }

    public function test_transfer_sides_remain_distinct_ledger_lines(): void
    {
        $out = new StockLedger(['id' => 31, 'transaction_type' => 'stock_transfer_out', 'reference_type' => 'App\\Models\\StockTransferVoucher', 'reference_id' => 9, 'quantity_in' => 0, 'quantity_out' => 6, 'stock_status' => 'saleable']);
        $in = new StockLedger(['id' => 32, 'transaction_type' => 'stock_transfer_in', 'reference_type' => 'App\\Models\\StockTransferVoucher', 'reference_id' => 9, 'quantity_in' => 6, 'quantity_out' => 0, 'stock_status' => 'saleable']);

        $rows = (new InventoryMovementService())->normalizeCollection(new Collection([$out, $in]));

        $this->assertCount(2, $rows);
        $this->assertSame('Stock Transfer Out', $rows[0]['movement_type']);
        $this->assertSame('Stock Transfer In', $rows[1]['movement_type']);
    }
}
