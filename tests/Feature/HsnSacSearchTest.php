<?php

namespace Tests\Feature;

use App\Models\HsnMaster;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithCompanyAccess;
use Tests\TestCase;

class HsnSacSearchTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithCompanyAccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allowNullableHsnMasterGstRateForSqlite();
    }

    public function test_goods_search_returns_hsn_and_not_sac(): void
    {
        $businessId = $this->business();
        $user = $this->createAccessibleUser($businessId, ['role_id' => 2, 'is_active' => 1, 'status' => 'active']);

        $this->hsn($businessId, ['hsn_code' => '1514', 'code_type' => 'HSN', 'description' => 'Rapeseed mustard or colza oil']);
        $this->hsn($businessId, ['hsn_code' => '998314', 'code_type' => 'SAC', 'description' => 'Information technology consulting services']);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=mustard&product_type=goods')
            ->assertOk()
            ->assertJsonPath('0.code_type', 'HSN')
            ->assertJsonMissing(['code_type' => 'SAC']);
    }

    public function test_service_search_returns_sac_and_not_hsn(): void
    {
        $businessId = $this->business();
        $user = $this->createAccessibleUser($businessId, ['role_id' => 2, 'is_active' => 1, 'status' => 'active']);

        $this->hsn($businessId, ['hsn_code' => '8517', 'code_type' => 'HSN', 'description' => 'Mobile phones']);
        $this->hsn($businessId, ['hsn_code' => '998314', 'code_type' => 'SAC', 'description' => 'Information technology consulting services']);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=consulting&product_type=service')
            ->assertOk()
            ->assertJsonPath('0.code_type', 'SAC')
            ->assertJsonMissing(['code_type' => 'HSN']);
    }

    public function test_exact_code_and_similar_product_ranking(): void
    {
        $businessId = $this->business();
        $user = $this->createAccessibleUser($businessId, ['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        $oil = $this->hsn($businessId, ['hsn_code' => '1514', 'code_type' => 'HSN', 'description' => 'Rapeseed mustard or colza oil']);
        $this->hsn($businessId, ['hsn_code' => '1509', 'code_type' => 'HSN', 'description' => 'Olive oil']);

        Product::query()->create([
            'company_id' => $businessId,
            'business_id' => $businessId,
            'name' => 'Fortune Mustard Oil 5L',
            'sku' => 'OIL-5L',
            'product_type' => 'goods',
            'item_type' => 'stock',
            'hsn_master_id' => $oil->id,
            'hsn_id' => $oil->id,
            'hsn_code' => '1514',
            'hsn' => '1514',
            'gst_rate' => 5,
            'selling_price' => 100,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=mustard oil&product_type=goods&product_name=Fortune Mustard Oil 1L')
            ->assertOk()
            ->assertJsonPath('0.hsn_code', '1514')
            ->assertJsonPath('0.match_source', 'similar_product');
    }

    public function test_tax_resolution_statuses_and_null_rate_is_not_zero(): void
    {
        $businessId = $this->business();
        $user = $this->createAccessibleUser($businessId, ['role_id' => 2, 'is_active' => 1, 'status' => 'active']);
        $hsn = $this->hsn($businessId, ['hsn_code' => '8471', 'code_type' => 'HSN', 'description' => 'Laptop computers', 'gst_rate' => null]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=8471&product_type=goods')
            ->assertOk()
            ->assertJsonPath('0.gst_rate', null)
            ->assertJsonPath('0.tax_resolution.status', 'no_verified_rule');

        DB::table('hsn_tax_rates')->insert([
            'hsn_id' => $hsn->id,
            'rule_name' => 'Laptop standard rule',
            'taxability' => 'taxable',
            'gst_rate' => 18,
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'cess_rate' => 0,
            'effective_from' => '2026-01-01',
            'verification_status' => 'verified',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['business_id' => $businessId])
            ->getJson('/app/inventory/hsn-search?q=8471&product_type=goods&transaction_date=2026-08-01')
            ->assertOk()
            ->assertJsonPath('0.tax_resolution.status', 'single_verified_rule')
            ->assertJsonPath('0.tax_resolution.rule.gst_rate', 18);
    }

    private function business(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'ABC Retail Pvt Ltd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function hsn(int $businessId, array $attributes): HsnMaster
    {
        return HsnMaster::query()->create(array_merge([
            'business_id' => $businessId,
            'taxability' => 'taxable',
            'gst_rate' => 0,
            'cess_rate' => 0,
            'verification_status' => 'classification_verified',
            'classification_verified' => true,
            'rate_verified' => true,
            'status' => 'active',
        ], $attributes));
    }

    private function allowNullableHsnMasterGstRateForSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' || !Schema::hasTable('hsn_masters')) {
            return;
        }

        $columns = DB::select('PRAGMA table_info(hsn_masters)');
        $gstRate = collect($columns)->firstWhere('name', 'gst_rate');

        if (!$gstRate || (int) $gstRate->notnull === 0) {
            return;
        }

        Schema::disableForeignKeyConstraints();
        DB::statement('ALTER TABLE hsn_masters RENAME TO hsn_masters_old');
        Schema::create('hsn_masters', function ($table) use ($columns) {
            foreach ($columns as $column) {
                if ($column->name === 'id') {
                    $table->id();
                    continue;
                }

                $definition = match ($column->type) {
                    'INTEGER' => $table->integer($column->name),
                    'BIGINT' => $table->unsignedBigInteger($column->name),
                    'TEXT' => $table->text($column->name),
                    'DATE' => $table->date($column->name),
                    'DATETIME' => $table->dateTime($column->name),
                    default => str_contains($column->type, 'DECIMAL')
                        ? $table->decimal($column->name, 8, 2)
                        : $table->string($column->name),
                };

                if ($column->name !== 'gst_rate' && (int) $column->notnull === 1) {
                    $definition->nullable(false);
                } else {
                    $definition->nullable();
                }
            }
        });

        $columnList = collect($columns)->pluck('name')->implode(', ');
        DB::statement("INSERT INTO hsn_masters ({$columnList}) SELECT {$columnList} FROM hsn_masters_old");
        Schema::drop('hsn_masters_old');
        $this->recreateProductsForSqliteHsnSearch();
        $this->recreateHsnTaxRatesForSqliteHsnSearch();
        Schema::enableForeignKeyConstraints();
    }

    private function recreateProductsForSqliteHsnSearch(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::drop('products');
        Schema::create('products', function ($table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('name')->nullable();
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->string('product_type')->nullable();
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('hsn_master_id')->nullable();
            $table->unsignedBigInteger('hsn_id')->nullable();
            $table->string('hsn_code')->nullable();
            $table->string('hsn')->nullable();
            $table->decimal('gst_rate', 8, 2)->nullable();
            $table->decimal('selling_price', 15, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    private function recreateHsnTaxRatesForSqliteHsnSearch(): void
    {
        if (!Schema::hasTable('hsn_tax_rates')) {
            return;
        }

        Schema::drop('hsn_tax_rates');
        Schema::create('hsn_tax_rates', function ($table) {
            $table->id();
            $table->unsignedBigInteger('hsn_id');
            $table->string('rule_name')->nullable();
            $table->text('rule_description')->nullable();
            $table->text('condition_text')->nullable();
            $table->string('taxability')->nullable();
            $table->decimal('gst_rate', 8, 2)->nullable();
            $table->decimal('cgst_rate', 8, 2)->nullable();
            $table->decimal('sgst_rate', 8, 2)->nullable();
            $table->decimal('igst_rate', 8, 2)->nullable();
            $table->decimal('cess_rate', 8, 2)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('notification_number')->nullable();
            $table->string('source_reference')->nullable();
            $table->string('verification_status')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }
}
