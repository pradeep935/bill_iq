<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->salesDocuments();
        $this->purchaseDocuments();
        $this->supportTables();
        $this->seedPermissions();
    }

    private function salesDocuments(): void
    {
        if (!Schema::hasTable('quotations')) {
            Schema::create('quotations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('quotation_number', 50);
                $table->date('quotation_date');
                $table->date('valid_until')->nullable();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->json('customer_snapshot_json')->nullable();
                $table->text('billing_address_snapshot')->nullable();
                $table->text('shipping_address_snapshot')->nullable();
                $table->unsignedBigInteger('sales_person_id')->nullable()->index();
                $table->unsignedBigInteger('price_list_id')->nullable()->index();
                $table->unsignedBigInteger('currency_id')->nullable()->index();
                $table->decimal('exchange_rate', 15, 6)->default(1);
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->string('discount_type', 20)->nullable();
                $table->decimal('discount_value', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->decimal('cgst', 15, 2)->default(0);
                $table->decimal('sgst', 15, 2)->default(0);
                $table->decimal('igst', 15, 2)->default(0);
                $table->decimal('cess', 15, 2)->default(0);
                $table->decimal('shipping_amount', 15, 2)->default(0);
                $table->decimal('round_off', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->text('notes')->nullable();
                $table->text('internal_notes')->nullable();
                $table->text('terms_conditions')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->string('approval_status', 30)->default('pending')->index();
                $table->unsignedBigInteger('converted_sales_order_id')->nullable()->index();
                $table->string('expiry_status', 30)->default('valid')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'quotation_number']);
            });
        }

        if (!Schema::hasTable('quotation_items')) {
            Schema::create('quotation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->string('description')->nullable();
                $table->decimal('quantity', 15, 3);
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->decimal('unit_price', 15, 2);
                $table->decimal('discount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->json('gst_snapshot')->nullable();
                $table->decimal('total', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sales_orders')) {
            Schema::create('sales_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('order_number', 50);
                $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->date('order_date');
                $table->date('expected_delivery_date')->nullable();
                $table->unsignedBigInteger('sales_person_id')->nullable()->index();
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('tax', 15, 2)->default(0);
                $table->decimal('shipping', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->string('order_status', 40)->default('draft')->index();
                $table->string('payment_status', 30)->default('unpaid')->index();
                $table->string('reservation_status', 30)->default('none')->index();
                $table->string('dispatch_status', 30)->default('pending')->index();
                $table->string('invoice_status', 30)->default('not_invoiced')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'order_number']);
            });
        }

        if (!Schema::hasTable('sales_order_items')) {
            Schema::create('sales_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->string('description')->nullable();
                $table->decimal('ordered_quantity', 15, 3);
                $table->decimal('reserved_quantity', 15, 3)->default(0);
                $table->decimal('delivered_quantity', 15, 3)->default(0);
                $table->decimal('invoiced_quantity', 15, 3)->default(0);
                $table->decimal('cancelled_quantity', 15, 3)->default(0);
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->json('tax_snapshot')->nullable();
                $table->decimal('line_total', 15, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('delivery_challans')) {
            Schema::create('delivery_challans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('challan_number', 50);
                $table->date('challan_date');
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
                $table->string('vehicle_number')->nullable();
                $table->string('transporter_name')->nullable();
                $table->unsignedBigInteger('dispatch_person_id')->nullable()->index();
                $table->string('dispatch_reference')->nullable();
                $table->decimal('shipping_cost', 15, 2)->default(0);
                $table->json('dispatch_checklist_json')->nullable();
                $table->string('tracking_number')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('dispatched_at')->nullable();
                $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'challan_number']);
            });
        }

        if (!Schema::hasTable('delivery_challan_items')) {
            Schema::create('delivery_challan_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('delivery_challan_id')->constrained('delivery_challans')->cascadeOnDelete();
                $table->foreignId('sales_order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->unsignedBigInteger('serial_id')->nullable()->index();
                $table->decimal('ordered_quantity', 15, 3)->default(0);
                $table->decimal('dispatch_quantity', 15, 3);
                $table->decimal('pending_quantity', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->string('warehouse_location')->nullable();
                $table->json('package_snapshot')->nullable();
                $table->timestamps();
            });
        }
    }

    private function purchaseDocuments(): void
    {
        if (!Schema::hasTable('purchase_requisitions')) {
            Schema::create('purchase_requisitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('requisition_number', 50);
                $table->date('requisition_date');
                $table->string('department')->nullable();
                $table->unsignedBigInteger('requester_id')->nullable()->index();
                $table->string('priority', 20)->default('normal')->index();
                $table->date('required_date')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('converted_purchase_order_id')->nullable()->index();
                $table->timestamps();
                $table->unique(['business_id', 'requisition_number']);
            });
        }

        if (!Schema::hasTable('purchase_requisition_items')) {
            Schema::create('purchase_requisition_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->decimal('quantity', 15, 3);
                $table->decimal('approved_quantity', 15, 3)->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('po_number', 50);
                $table->foreignId('purchase_requisition_id')->nullable()->constrained('purchase_requisitions')->nullOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->date('po_date');
                $table->date('expected_delivery_date')->nullable();
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('discount_amount', 15, 2)->default(0);
                $table->decimal('taxable_amount', 15, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->string('status', 30)->default('draft')->index();
                $table->string('confirmation_status', 30)->default('pending')->index();
                $table->string('receipt_status', 30)->default('not_received')->index();
                $table->text('terms_conditions')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'po_number']);
            });
        }

        if (!Schema::hasTable('purchase_order_items')) {
            Schema::create('purchase_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
                $table->decimal('ordered_quantity', 15, 3);
                $table->decimal('received_quantity', 15, 3)->default(0);
                $table->decimal('rejected_quantity', 15, 3)->default(0);
                $table->decimal('returned_quantity', 15, 3)->default(0);
                $table->decimal('purchase_rate', 15, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->json('tax_snapshot')->nullable();
                $table->decimal('line_total', 15, 2)->default(0);
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('goods_receipts')) {
            Schema::create('goods_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('grn_number', 50);
                $table->date('receipt_date');
                $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
                $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
                $table->string('supplier_challan_number')->nullable();
                $table->string('qc_status', 30)->default('pending')->index();
                $table->string('status', 30)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'grn_number']);
            });
        }

        if (!Schema::hasTable('goods_receipt_items')) {
            Schema::create('goods_receipt_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
                $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
                $table->unsignedBigInteger('serial_id')->nullable()->index();
                $table->decimal('ordered_quantity', 15, 3)->default(0);
                $table->decimal('received_quantity', 15, 3)->default(0);
                $table->decimal('rejected_quantity', 15, 3)->default(0);
                $table->decimal('damaged_quantity', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->string('qc_status', 30)->default('pending')->index();
                $table->string('warehouse_location')->nullable();
                $table->timestamps();
            });
        }
    }

    private function supportTables(): void
    {
        if (!Schema::hasTable('supplier_confirmations')) {
            Schema::create('supplier_confirmations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
                $table->string('confirmation_status', 30);
                $table->date('expected_delivery_date')->nullable();
                $table->json('modified_items_json')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('back_orders')) {
            Schema::create('back_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('source_type');
                $table->unsignedBigInteger('source_id');
                $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
                $table->decimal('pending_quantity', 15, 3);
                $table->date('expected_date')->nullable();
                $table->string('priority', 20)->default('normal');
                $table->string('status', 30)->default('open')->index();
                $table->timestamps();
                $table->index(['business_id', 'source_type', 'source_id'], 'back_orders_source_index');
            });
        }

        if (!Schema::hasTable('order_status_histories')) {
            Schema::create('order_status_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('document_type');
                $table->unsignedBigInteger('document_id');
                $table->string('old_status')->nullable();
                $table->string('new_status');
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'document_type', 'document_id'], 'order_history_document_index');
            });
        }

        if (!Schema::hasTable('customer_price_lists')) {
            Schema::create('customer_price_lists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->decimal('min_quantity', 15, 3)->default(0);
                $table->decimal('price', 15, 2);
                $table->date('starts_at')->nullable();
                $table->date('ends_at')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->index(['business_id', 'customer_id', 'product_id'], 'customer_price_lookup_index');
            });
        }

        if (!Schema::hasTable('order_notifications')) {
            Schema::create('order_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('document_type');
                $table->unsignedBigInteger('document_id');
                $table->string('event_name');
                $table->string('channel', 30)->default('in_app');
                $table->string('status', 30)->default('pending')->index();
                $table->json('payload_json')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) return;
        $names = ['create quotation','approve quotation','convert quotation','create sales order','approve sales order','reserve stock','dispatch goods','create delivery challan','create purchase requisition','approve purchase requisition','create purchase order','approve purchase order','receive goods','approve goods receipt','cancel orders','reopen orders','view order reports','export order reports'];
        foreach ($names as $name) DB::table('permissions')->updateOrInsert(['name' => $name], ['module' => 'order_management', 'description' => ucfirst($name), 'created_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        foreach ([1, 2] as $roleId) foreach ($ids as $id) DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Order documents are retained intentionally.
    }
};
