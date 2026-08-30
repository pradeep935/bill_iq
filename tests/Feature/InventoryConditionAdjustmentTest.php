<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockAdjustmentVoucher;
use App\Models\StockLedger;
use App\Services\InventoryControlService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class InventoryConditionAdjustmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'stock_reservations',
            'warehouse_product_stocks',
            'stock_ledgers',
            'stock_adjustment_items',
            'stock_adjustment_vouchers',
            'stock_transfer_items',
            'stock_transfer_vouchers',
            'stock_count_items',
            'stock_count_sessions',
            'business_inventory_settings',
            'business_account_settings',
            'accounts',
            'product_images',
            'product_batches',
            'product_categories',
            'brands',
            'products',
            'warehouses',
            'branches',
            'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('product_type')->nullable();
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->string('unit')->nullable();
            $table->string('primary_barcode')->nullable();
            $table->string('barcode')->nullable();
            $table->string('hsn')->nullable();
            $table->string('hsn_code')->nullable();
            $table->decimal('reorder_stock', 15, 3)->default(0);
            $table->decimal('minimum_stock', 15, 3)->default(0);
            $table->decimal('maximum_stock', 15, 3)->default(0);
            $table->boolean('batch_required')->default(false);
            $table->boolean('serial_required')->default(false);
            $table->boolean('track_inventory')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->string('tracking_type')->nullable();
            $table->string('status')->default('active');
            $table->decimal('current_stock', 15, 3)->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('product_id');
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('image_path')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('account_type')->nullable();
            $table->timestamps();
        });

        Schema::create('business_inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('stock_adjustment_gain_account_id')->nullable();
            $table->unsignedBigInteger('stock_adjustment_loss_account_id')->nullable();
            $table->timestamps();
        });

        Schema::create('business_account_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('inventory_account_id')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_adjustment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('voucher_number');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->date('adjustment_date');
            $table->unsignedBigInteger('adjustment_reason_id')->nullable();
            $table->string('adjustment_type')->default('mixed');
            $table->string('source')->default('manual');
            $table->string('status')->default('draft');
            $table->text('remarks')->nullable();
            $table->decimal('total_quantity_in', 15, 3)->default(0);
            $table->decimal('total_quantity_out', 15, 3)->default(0);
            $table->decimal('total_value_in', 15, 2)->default(0);
            $table->decimal('total_value_out', 15, 2)->default(0);
            $table->unsignedBigInteger('journal_voucher_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_adjustment_voucher_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('system_quantity', 15, 3)->default(0);
            $table->decimal('actual_quantity', 15, 3)->nullable();
            $table->decimal('adjustment_quantity', 15, 3);
            $table->string('direction', 10);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('adjustment_value', 15, 2)->default(0);
            $table->string('warehouse_location')->nullable();
            $table->string('reason')->nullable();
            $table->string('condition_status', 30)->nullable();
            $table->string('source_condition_status', 30)->nullable();
            $table->string('destination_condition_status', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_ledgers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('transaction_type');
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->decimal('quantity_in', 15, 3)->default(0);
            $table->decimal('quantity_out', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('stock_value', 15, 2)->default(0);
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->string('warehouse_location')->nullable();
            $table->string('stock_status', 30)->default('saleable');
            $table->timestamp('transaction_date')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->decimal('reserved_quantity', 15, 3)->default(0);
            $table->decimal('fulfilled_quantity', 15, 3)->default(0);
            $table->decimal('released_quantity', 15, 3)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('stock_transfer_vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('source_warehouse_id')->nullable();
            $table->unsignedBigInteger('destination_warehouse_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_transfer_voucher_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
        });

        Schema::create('stock_count_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_count_session_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
        });

        Schema::create('warehouse_product_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('warehouse_location_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->decimal('quantity_on_hand', 15, 3)->default(0);
            $table->decimal('available_quantity', 15, 3)->default(0);
            $table->decimal('average_cost', 15, 2)->default(0);
            $table->decimal('stock_value', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function test_saleable_stock_out_reduces_saleable_and_physical_stock(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 10);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'damaged', 6);

        $voucher = $this->postAdjustment($branch, $warehouse, $product->id, 'saleable', 3);

        $this->assertConditionBalances($businessId, $branch, $warehouse, $product->id, saleable: 7, damaged: 6, physical: 13);
        $this->assertLedgerOut($voucher, 'saleable', 3);
        $this->assertMovementRow($product->id, 'Saleable -> Out', -3, -3);
    }

    public function test_damaged_stock_out_reduces_damaged_and_physical_stock(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 323);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'damaged', 6);

        $voucher = $this->postAdjustment($branch, $warehouse, $product->id, 'damaged', 2);

        $this->assertConditionBalances($businessId, $branch, $warehouse, $product->id, saleable: 323, damaged: 4, physical: 327);
        $this->assertLedgerOut($voucher, 'damaged', 2);
        $this->assertMovementRow($product->id, 'Damaged -> Out', -2, -2);
    }

    public function test_expired_stock_out_shows_expired_to_out_movement(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 10);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'expired', 5);

        $voucher = $this->postAdjustment($branch, $warehouse, $product->id, 'expired', 2);

        $this->assertSame(10.0, app(StockService::class)->getConditionStock(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product->id], 'saleable'));
        $this->assertSame(3.0, app(StockService::class)->getConditionStock(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product->id], 'expired'));
        $this->assertSame(13.0, app(StockService::class)->getPhysicalStock(['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product->id]));
        $this->assertLedgerOut($voucher, 'expired', 2);
        $this->assertMovementRow($product->id, 'Expired -> Out', -2, -2);
    }

    public function test_exact_mustard_oil_damaged_stock_out_result_is_319_4_323(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 319);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'damaged', 6);

        $voucher = $this->postAdjustment($branch, $warehouse, $product->id, 'damaged', 2);

        $this->assertConditionBalances($businessId, $branch, $warehouse, $product->id, saleable: 319, damaged: 4, physical: 323);
        $this->assertLedgerOut($voucher, 'damaged', 2);
        $this->assertConditionSurfaces($businessId, $branch, $warehouse, $product->id, saleable: 319, damaged: 4, physical: 323);
        $this->assertMovementRow($product->id, 'Damaged -> Out', -2, -2);
    }

    public function test_stock_out_uses_selected_condition_when_source_metadata_is_stale(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 321);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'damaged', 6);

        $voucher = app(InventoryControlService::class)->saveAdjustment([
            'branch_id' => $branch,
            'warehouse_id' => $warehouse,
            'adjustment_date' => now()->toDateString(),
            'adjustment_reason_id' => null,
            'adjustment_type' => 'decrease',
            'source' => 'manual',
            'status' => 'posted',
            'remarks' => 'OTHER_OUT',
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => null,
                'batch_id' => null,
                'unit_id' => null,
                'adjustment_quantity' => 2,
                'direction' => 'out',
                'unit_cost' => 10,
                'warehouse_location' => null,
                'condition_status' => 'damaged',
                'source_condition_status' => 'saleable',
                'reason' => null,
            ]],
        ]);

        $this->assertConditionBalances($businessId, $branch, $warehouse, $product->id, saleable: 321, damaged: 4, physical: 325);
        $this->assertLedgerOut($voucher, 'damaged', 2);
        $this->assertDatabaseHas('stock_adjustment_items', [
            'stock_adjustment_voucher_id' => $voucher->id,
            'condition_status' => 'damaged',
            'source_condition_status' => 'damaged',
        ]);
    }

    public function test_damaged_to_saleable_condition_transfer_keeps_physical_stock(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 10);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'damaged', 6);

        $this->postConditionTransfer($branch, $warehouse, $product->id, 'damaged', 'saleable', 2);

        $this->assertConditionBalances($businessId, $branch, $warehouse, $product->id, saleable: 12, damaged: 4, physical: 16);
        $this->assertMovementRow($product->id, 'Damaged -> Saleable', 2, 0);
    }

    public function test_saleable_to_damaged_condition_transfer_keeps_physical_stock(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 10);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'damaged', 6);

        $this->postConditionTransfer($branch, $warehouse, $product->id, 'saleable', 'damaged', 2);

        $this->assertConditionBalances($businessId, $branch, $warehouse, $product->id, saleable: 8, damaged: 8, physical: 16);
        $this->assertMovementRow($product->id, 'Saleable -> Damaged', 2, 0);
    }

    public function test_lost_stock_is_not_counted_as_physical_stock(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 10);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'lost', 3);

        $this->assertSame(10.0, app(StockService::class)->getPhysicalStock([
            'business_id' => $businessId,
            'branch_id' => $branch,
            'warehouse_id' => $warehouse,
            'product_id' => $product->id,
        ]));

        $dashboard = app(StockService::class)->dashboard(['product_id' => $product->id]);

        $this->assertSame(10.0, (float) $dashboard['physical_quantity']);
        $this->assertSame(10.0, (float) $dashboard['saleable_quantity']);
    }

    public function test_stock_out_cannot_exceed_selected_condition_balance(): void
    {
        [$businessId, $product, $branch, $warehouse] = $this->fixture();
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'saleable', 10);
        $this->seedConditionStock($businessId, $branch, $warehouse, $product->id, 'damaged', 1);

        $this->expectException(ValidationException::class);

        try {
            $this->postAdjustment($branch, $warehouse, $product->id, 'damaged', 2);
        } finally {
            $this->assertConditionBalances($businessId, $branch, $warehouse, $product->id, saleable: 10, damaged: 1, physical: 11);
        }
    }

    private function fixture(): array
    {
        $businessId = DB::table('companies')->insertGetId(['name' => 'Bill IQ Test', 'created_at' => now(), 'updated_at' => now()]);
        $branch = DB::table('branches')->insertGetId(['business_id' => $businessId, 'name' => 'Main', 'code' => 'MAIN', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = DB::table('warehouses')->insertGetId(['business_id' => $businessId, 'branch_id' => $branch, 'name' => 'Store', 'code' => 'ST', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $product = Product::query()->create(['business_id' => $businessId, 'company_id' => $businessId, 'name' => 'Mustard Oil 1L', 'sku' => 'MO-1L', 'product_type' => 'goods', 'item_type' => 'stock', 'track_inventory' => true, 'tracking_type' => 'none', 'status' => 'active']);

        session(['business_id' => $businessId]);

        return [$businessId, $product, $branch, $warehouse];
    }

    private function seedConditionStock(int $businessId, int $branch, int $warehouse, int $productId, string $condition, float $quantity): void
    {
        app(StockService::class)->increaseStock([
            'business_id' => $businessId,
            'branch_id' => $branch,
            'warehouse_id' => $warehouse,
            'product_id' => $productId,
            'transaction_type' => 'opening_stock',
            'reference_type' => Product::class,
            'reference_id' => $productId,
            'quantity' => $quantity,
            'unit_cost' => 10,
            'stock_status' => $condition,
            'transaction_date' => now(),
        ]);
    }

    private function postAdjustment(int $branch, int $warehouse, int $productId, string $condition, float $quantity): StockAdjustmentVoucher
    {
        return app(InventoryControlService::class)->saveAdjustment([
            'branch_id' => $branch,
            'warehouse_id' => $warehouse,
            'adjustment_date' => now()->toDateString(),
            'adjustment_reason_id' => null,
            'adjustment_type' => 'decrease',
            'source' => 'manual',
            'status' => 'posted',
            'remarks' => 'Condition stock out test',
            'items' => [[
                'product_id' => $productId,
                'product_variant_id' => null,
                'batch_id' => null,
                'unit_id' => null,
                'adjustment_quantity' => $quantity,
                'direction' => 'out',
                'unit_cost' => 10,
                'warehouse_location' => null,
                'condition_status' => $condition,
                'reason' => null,
            ]],
        ]);
    }

    private function postConditionTransfer(int $branch, int $warehouse, int $productId, string $fromCondition, string $toCondition, float $quantity): StockAdjustmentVoucher
    {
        return app(InventoryControlService::class)->saveAdjustment([
            'branch_id' => $branch,
            'warehouse_id' => $warehouse,
            'adjustment_date' => now()->toDateString(),
            'adjustment_reason_id' => null,
            'adjustment_type' => 'condition_transfer',
            'source' => 'condition_transfer',
            'status' => 'posted',
            'remarks' => 'Condition transfer test',
            'items' => [[
                'product_id' => $productId,
                'product_variant_id' => null,
                'batch_id' => null,
                'unit_id' => null,
                'adjustment_quantity' => $quantity,
                'direction' => 'transfer',
                'unit_cost' => 10,
                'warehouse_location' => null,
                'condition_status' => $toCondition,
                'source_condition_status' => $fromCondition,
                'destination_condition_status' => $toCondition,
                'reason' => null,
            ]],
        ]);
    }

    private function assertConditionBalances(int $businessId, int $branch, int $warehouse, int $productId, float $saleable, float $damaged, float $physical): void
    {
        $stock = app(StockService::class);
        $scope = ['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $productId];

        $this->assertSame($saleable, $stock->getConditionStock($scope, 'saleable'));
        $this->assertSame($damaged, $stock->getConditionStock($scope, 'damaged'));
        $this->assertSame($physical, $stock->getPhysicalStock($scope));
    }

    private function assertConditionSurfaces(int $businessId, int $branch, int $warehouse, int $productId, float $saleable, float $damaged, float $physical): void
    {
        $stock = app(StockService::class);
        $inventory = app(InventoryControlService::class);
        $scope = ['business_id' => $businessId, 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $productId];

        $summary = $stock->summary(['product_id' => $productId, 'view_mode' => 'detailed', 'per_page' => 10])->getCollection()->first();
        $dashboard = $stock->dashboard(['product_id' => $productId]);
        $reports = $inventory->reports(['product_id' => $productId]);
        $branchRow = $reports['branch_report']->first();
        $warehouseRow = $reports['warehouse_report']->first();
        $conditionRows = collect($stock->productInventoryDetail($productId)['condition_stock'])->keyBy('condition');

        $this->assertSame($saleable, $stock->getCurrentStock($scope));
        $this->assertSame($saleable, (float) $summary->saleable_quantity);
        $this->assertSame($damaged, (float) $summary->damaged_quantity);
        $this->assertSame($physical, (float) $summary->physical_quantity);
        $this->assertSame($saleable, (float) $dashboard['saleable_quantity']);
        $this->assertSame($damaged, (float) $dashboard['damaged_quantity']);
        $this->assertSame($physical, (float) $dashboard['physical_quantity']);
        $this->assertSame($saleable, (float) $branchRow->saleable_quantity);
        $this->assertSame($damaged, (float) $branchRow->damaged_quantity);
        $this->assertSame($physical, (float) $branchRow->physical_quantity);
        $this->assertSame($saleable, (float) $warehouseRow->saleable_quantity);
        $this->assertSame($damaged, (float) $warehouseRow->damaged_quantity);
        $this->assertSame($physical, (float) $warehouseRow->physical_quantity);
        $this->assertSame($saleable, (float) $conditionRows['saleable']['quantity']);
        $this->assertSame($damaged, (float) $conditionRows['damaged']['quantity']);
        $this->assertGreaterThanOrEqual(3, $reports['ledger']->count());
    }

    private function assertMovementRow(int $productId, string $movement, float $quantity, float $physicalChange): void
    {
        $reports = app(InventoryControlService::class)->reports(['product_id' => $productId]);
        $row = collect($reports['movement_report'])
            ->first(fn ($item) => $item->movement === $movement && (float) $item->display_quantity === $quantity);

        $this->assertNotNull($row, "Missing movement row {$movement} / {$quantity}.");
        $this->assertSame($physicalChange, (float) $row->physical_change);
    }

    private function assertLedgerOut(StockAdjustmentVoucher $voucher, string $condition, float $quantity): void
    {
        $this->assertDatabaseHas('stock_ledgers', [
            'reference_type' => StockAdjustmentVoucher::class,
            'reference_id' => $voucher->id,
            'stock_status' => $condition,
            'quantity_out' => $quantity,
        ]);
    }
}
