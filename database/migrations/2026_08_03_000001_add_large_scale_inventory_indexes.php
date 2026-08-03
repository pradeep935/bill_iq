<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('products', ['business_id', 'status', 'deleted_at'], 'products_business_status_deleted_idx');
        $this->addIndex('products', ['business_id', 'sku', 'deleted_at'], 'products_business_sku_deleted_idx');
        $this->addIndex('products', ['business_id', 'primary_barcode'], 'products_business_primary_barcode_idx');
        $this->addIndex('products', ['business_id', 'product_type', 'item_type'], 'products_business_type_item_idx');
        $this->addIndex('products', ['business_id', 'category_id', 'brand_id'], 'products_business_category_brand_idx');
        $this->addIndex('products', ['business_id', 'unit', 'gst_rate'], 'products_business_unit_gst_idx');

        $this->addIndex('product_barcodes', ['business_id', 'product_id', 'is_primary'], 'product_barcodes_business_product_primary_idx');
        $this->addIndex('product_barcodes', ['business_id', 'status'], 'product_barcodes_business_status_idx');
        $this->addIndex('product_prices', ['business_id', 'product_id', 'price_type'], 'product_prices_business_product_type_idx');
        $this->addIndex('product_images', ['business_id', 'product_id', 'is_primary'], 'product_images_business_product_primary_idx');
        $this->addIndex('product_batches', ['business_id', 'product_id', 'status'], 'product_batches_business_product_status_idx');
        $this->addIndex('product_serial_numbers', ['business_id', 'product_id', 'status'], 'product_serial_numbers_business_product_status_idx');
        $this->addIndex('product_variant_items', ['business_id', 'product_id', 'sku'], 'product_variant_items_business_product_sku_idx');
        $this->addIndex('hsn_masters', ['status', 'hsn_code'], 'hsn_masters_status_code_idx');
    }

    public function down(): void
    {
        foreach ([
            ['products', 'products_business_status_deleted_idx'],
            ['products', 'products_business_sku_deleted_idx'],
            ['products', 'products_business_primary_barcode_idx'],
            ['products', 'products_business_type_item_idx'],
            ['products', 'products_business_category_brand_idx'],
            ['products', 'products_business_unit_gst_idx'],
            ['product_barcodes', 'product_barcodes_business_product_primary_idx'],
            ['product_barcodes', 'product_barcodes_business_status_idx'],
            ['product_prices', 'product_prices_business_product_type_idx'],
            ['product_images', 'product_images_business_product_primary_idx'],
            ['product_batches', 'product_batches_business_product_status_idx'],
            ['product_serial_numbers', 'product_serial_numbers_business_product_status_idx'],
            ['product_variant_items', 'product_variant_items_business_product_sku_idx'],
            ['hsn_masters', 'hsn_masters_status_code_idx'],
        ] as [$table, $index]) {
            if (Schema::hasTable($table) && $this->hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if (!Schema::hasTable($table) || $this->hasIndex($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => ($index['name'] ?? null) === $name);
    }
};
