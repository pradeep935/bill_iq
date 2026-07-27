<?php

namespace App\Console\Commands;

use App\Http\Controllers\AppController;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLedger;
use App\Models\Warehouse;
use App\Models\WarehouseProductStock;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ManageInventoryDemoData extends Command
{
    protected $signature = 'inventory:demo-data {action=status : status, seed, or clear}';

    protected $description = 'Manage tagged BillIQ inventory demo data for batch, expiry, current stock and ledger testing.';

    private const SKU = 'BATCH-DEMO-001';
    private const BATCH = 'BDM-2026-07';
    private const REMARKS = 'Managed inventory demo data';

    public function handle(StockService $stock): int
    {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'seed' => $this->seed($stock),
            'clear' => $this->clear(),
            'status' => $this->status(),
            default => $this->invalidAction(),
        };
    }

    private function seed(StockService $stock): int
    {
        DB::transaction(function () use ($stock) {
            $businessId = AppController::businessId();
            $branch = Branch::query()->where('business_id', $businessId)->orderBy('id')->first();
            $warehouse = Warehouse::query()
                ->where('business_id', $businessId)
                ->when($branch, fn ($q) => $q->where('branch_id', $branch->id))
                ->orderBy('id')
                ->first() ?: Warehouse::query()->where('business_id', $businessId)->orderBy('id')->first();

            $product = Product::query()->withTrashed()->updateOrCreate(
                ['sku' => self::SKU],
                array_filter([
                    'business_id' => $businessId,
                    'company_id' => $businessId,
                    'tenant_id' => $businessId,
                    'name' => 'Batch Demo Medicine',
                    'product_name' => 'Batch Demo Medicine',
                    'sku' => self::SKU,
                    'barcode' => 'BDM001',
                    'primary_barcode' => 'BDM001',
                    'product_type' => 'goods',
                    'item_type' => 'stock',
                    'unit' => 'PCS',
                    'cost_price' => 50,
                    'selling_price' => 80,
                    'mrp' => 100,
                    'batch_required' => 1,
                    'expiry_required' => 1,
                    'status' => 'active',
                    'deleted_at' => null,
                ], fn ($value, $column) => Schema::hasColumn('products', $column), ARRAY_FILTER_USE_BOTH)
            );

            $identity = ['product_id' => $product->id, 'batch_no' => self::BATCH];
            if (Schema::hasColumn('product_batches', 'business_id')) $identity['business_id'] = $businessId;
            if (Schema::hasColumn('product_batches', 'tenant_id')) $identity['tenant_id'] = $businessId;

            $batch = ProductBatch::query()->updateOrCreate(
                $identity,
                array_filter([
                    'business_id' => $businessId,
                    'tenant_id' => $businessId,
                    'batch_number' => self::BATCH,
                    'manufacturing_date' => now()->subMonth()->toDateString(),
                    'mfg_date' => now()->subMonth()->toDateString(),
                    'expiry_date' => now()->addDays(20)->toDateString(),
                    'purchase_price' => 50,
                    'cost_price' => 50,
                    'selling_price' => 80,
                    'quantity' => 0,
                    'stock_qty' => 0,
                    'status' => 'active',
                    'blocked_reason' => null,
                    'posted_by' => 1,
                    'posted_at' => now(),
                ], fn ($value, $column) => Schema::hasColumn('product_batches', $column), ARRAY_FILTER_USE_BOTH)
            );

            StockLedger::query()
                ->where('business_id', $businessId)
                ->where('product_id', $product->id)
                ->where('batch_id', $batch->id)
                ->whereIn('remarks', ['Persisted batch module verification stock', 'Batch smoke test'])
                ->update(['remarks' => self::REMARKS]);

            $ledgerExists = StockLedger::query()
                ->where('business_id', $businessId)
                ->where('product_id', $product->id)
                ->where('batch_id', $batch->id)
                ->exists();

            if (!$ledgerExists) {
                $stock->increaseStock([
                    'business_id' => $businessId,
                    'branch_id' => $branch?->id,
                    'warehouse_id' => $warehouse?->id,
                    'product_id' => $product->id,
                    'batch_id' => $batch->id,
                    'transaction_type' => 'opening_stock',
                    'reference_type' => ProductBatch::class,
                    'reference_id' => $batch->id,
                    'quantity' => 10,
                    'unit_cost' => 50,
                    'transaction_date' => now(),
                    'remarks' => self::REMARKS,
                ]);
            }
        });

        $this->info('Inventory demo data seeded.');
        return $this->status();
    }

    private function clear(): int
    {
        DB::transaction(function () {
            $product = Product::query()->withTrashed()->where('sku', self::SKU)->first();
            if (!$product) {
                return;
            }

            $batchIds = ProductBatch::query()->where('product_id', $product->id)->where('batch_no', self::BATCH)->pluck('id');
            StockLedger::query()->where('product_id', $product->id)->whereIn('batch_id', $batchIds)->delete();
            if (Schema::hasTable('warehouse_product_stocks')) {
                WarehouseProductStock::query()->where('product_id', $product->id)->whereIn('batch_id', $batchIds)->delete();
            }
            ProductBatch::query()->whereIn('id', $batchIds)->delete();
            $product->forceDelete();
        });

        $this->info('Inventory demo data cleared.');
        return $this->status();
    }

    private function status(): int
    {
        $product = Product::query()->withTrashed()->where('sku', self::SKU)->first();
        $batch = $product ? ProductBatch::query()->where('product_id', $product->id)->where('batch_no', self::BATCH)->first() : null;
        $ledgerCount = $batch ? StockLedger::query()->where('batch_id', $batch->id)->count() : 0;
        $qty = $batch ? app(StockService::class)->getCurrentStock(['business_id' => AppController::businessId(), 'product_id' => $product->id, 'batch_id' => $batch->id]) : 0;

        $this->table(['Product', 'Batch', 'Ledger Rows', 'Qty'], [[
            $product ? $product->sku . ' #' . $product->id : 'missing',
            $batch ? $batch->batch_no . ' #' . $batch->id : 'missing',
            $ledgerCount,
            number_format((float) $qty, 3),
        ]]);

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Invalid action. Use: status, seed, or clear.');
        return self::FAILURE;
    }
}
