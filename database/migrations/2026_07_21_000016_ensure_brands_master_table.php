<?php

use App\Http\Controllers\AppController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('brands')) {
            Schema::create('brands', function (Blueprint $table) {
                $table->id();
                if (Schema::hasTable('companies')) {
                    $table->foreignId('business_id')->nullable()->constrained('companies')->cascadeOnDelete();
                } else {
                    $table->unsignedBigInteger('business_id')->nullable();
                }
                $table->string('name');
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();

                $table->unique(['business_id', 'name']);
            });
        }

        if (!Schema::hasColumn('products', 'brand_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('products', 'brand')) {
            return;
        }

        $businessId = AppController::businessId();
        $productBusinessColumn = collect(['business_id', 'tenant_id', 'company_id'])
            ->first(fn ($column) => Schema::hasColumn('products', $column));
        $brandNames = DB::table('products')
            ->whereNotNull('brand')
            ->where('brand', '<>', '')
            ->distinct()
            ->pluck('brand');

        foreach ($brandNames as $brandName) {
            DB::table('brands')->updateOrInsert(
                [
                    'business_id' => $businessId,
                    'name' => $brandName,
                ],
                [
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (Schema::hasColumn('products', 'brand_id')) {
            DB::table('products')
                ->whereNotNull('brand')
                ->where('brand', '<>', '')
                ->whereNull('brand_id')
                ->orderBy('id')
                ->chunkById(100, function ($products) use ($businessId, $productBusinessColumn) {
                    foreach ($products as $product) {
                        $productBusinessId = $productBusinessColumn
                            ? ($product->{$productBusinessColumn} ?: $businessId)
                            : $businessId;
                        $brandId = DB::table('brands')
                            ->where('business_id', $productBusinessId)
                            ->where('name', $product->brand)
                            ->value('id');

                        if ($brandId) {
                            DB::table('products')
                                ->where('id', $product->id)
                                ->update(['brand_id' => $brandId]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // Keep brand master data intact.
    }
};
