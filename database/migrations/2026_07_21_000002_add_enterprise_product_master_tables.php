<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'product_name')) {
                $table->string('product_name', 255)->nullable()->after('barcode');
            }

            if (!Schema::hasColumn('products', 'subcategory')) {
                $table->string('subcategory', 150)->nullable()->after('category');
            }

            if (!Schema::hasColumn('products', 'tax_group_id')) {
                $table->unsignedBigInteger('tax_group_id')->nullable()->after('unit_id');
            }

            if (!Schema::hasColumn('products', 'item_type')) {
                $table->string('item_type', 30)->default('stock')->after('product_type');
            }

            if (!Schema::hasColumn('products', 'wholesale_price')) {
                $table->decimal('wholesale_price', 15, 2)->default(0)->after('mrp');
            }

            if (!Schema::hasColumn('products', 'dealer_price')) {
                $table->decimal('dealer_price', 15, 2)->default(0)->after('wholesale_price');
            }

            if (!Schema::hasColumn('products', 'online_price')) {
                $table->decimal('online_price', 15, 2)->default(0)->after('dealer_price');
            }

            if (!Schema::hasColumn('products', 'weight')) {
                $table->decimal('weight', 10, 3)->nullable()->after('maximum_stock');
            }

            if (!Schema::hasColumn('products', 'length')) {
                $table->decimal('length', 10, 3)->nullable()->after('weight');
            }

            if (!Schema::hasColumn('products', 'width')) {
                $table->decimal('width', 10, 3)->nullable()->after('length');
            }

            if (!Schema::hasColumn('products', 'height')) {
                $table->decimal('height', 10, 3)->nullable()->after('width');
            }

            if (!Schema::hasColumn('products', 'expiry_required')) {
                $table->boolean('expiry_required')->default(false)->after('height');
            }

            if (!Schema::hasColumn('products', 'batch_required')) {
                $table->boolean('batch_required')->default(false)->after('expiry_required');
            }

            if (!Schema::hasColumn('products', 'serial_required')) {
                $table->boolean('serial_required')->default(false)->after('batch_required');
            }
        });

        if (!Schema::hasTable('product_barcodes')) {
            Schema::create('product_barcodes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('barcode', 100);
                $table->string('barcode_type', 30)->default('internal');
                $table->string('type', 30)->default('internal');
                $table->decimal('quantity', 15, 3)->default(1);
                $table->boolean('is_primary')->default(false);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();

                $table->unique(['business_id', 'barcode']);
                $table->index(['product_id', 'is_primary']);
            });
        } else {
            Schema::table('product_barcodes', function (Blueprint $table) {
            if (!Schema::hasColumn('product_barcodes', 'barcode_type')) {
                $table->string('barcode_type', 30)->default('internal')->after('barcode');
            }

            });
        }

        if (!Schema::hasTable('product_batches')) {
            Schema::create('product_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('batch_no', 100);
                $table->string('batch_number', 100)->nullable();
                $table->date('manufacturing_date')->nullable();
                $table->date('expiry_date')->nullable();
                $table->decimal('purchase_price', 15, 2)->default(0);
                $table->decimal('selling_price', 15, 2)->default(0);
                $table->decimal('mrp', 15, 2)->nullable();
                $table->decimal('quantity', 15, 3)->default(0);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();

                $table->unique(['business_id', 'product_id', 'batch_no']);
                $table->index(['business_id', 'expiry_date']);
            });
        } else {
            Schema::table('product_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('product_batches', 'batch_no')) {
                $table->string('batch_no')->nullable()->after('product_id');
            }

            if (!Schema::hasColumn('product_batches', 'purchase_price')) {
                $table->decimal('purchase_price', 15, 2)->default(0)->after('expiry_date');
            }

            if (!Schema::hasColumn('product_batches', 'selling_price')) {
                $table->decimal('selling_price', 15, 2)->default(0)->after('purchase_price');
            }

            if (!Schema::hasColumn('product_batches', 'quantity')) {
                $table->decimal('quantity', 15, 3)->default(0)->after('selling_price');
            }
            });
        }

        if (!Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('image_path');
                $table->string('image_type', 30)->default('gallery');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'product_id']);
                $table->index(['product_id', 'is_primary']);
            });
        }

        if (!Schema::hasTable('product_prices')) {
            Schema::create('product_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('price_type', 40);
                $table->decimal('price', 15, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['product_id', 'price_type']);
                $table->index(['business_id', 'price_type']);
            });
        }

        if (!Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('variant_name', 100);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'product_id']);
            });
        }

        if (!Schema::hasTable('product_variant_values')) {
            Schema::create('product_variant_values', function (Blueprint $table) {
                $table->id();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->string('value', 100);
                $table->timestamps();

                $table->unique(['variant_id', 'value']);
            });
        }

        if (!Schema::hasTable('product_variant_items')) {
            Schema::create('product_variant_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('sku', 100);
                $table->string('barcode', 100)->nullable();
                $table->decimal('purchase_price', 15, 2)->default(0);
                $table->decimal('selling_price', 15, 2)->default(0);
                $table->decimal('mrp', 15, 2)->nullable();
                $table->decimal('current_stock', 15, 3)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'sku']);
                $table->index(['business_id', 'barcode']);
                $table->index(['business_id', 'product_id']);
            });
        }

        if (!Schema::hasTable('product_serials')) {
            Schema::create('product_serials', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('serial_number', 100);
                $table->string('status', 30)->default('available')->index();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'serial_number']);
                $table->index(['business_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
        Schema::dropIfExists('product_variant_items');
        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_prices');
        // Existing products, product_barcodes and product_batches data is preserved intentionally.
    }
};
