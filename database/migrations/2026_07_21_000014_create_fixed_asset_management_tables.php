<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->settingsAndMasters();
        $this->registerAndAcquisition();
        $this->depreciationAndMovement();
        $this->maintenanceAndProtection();
        $this->valuationDisposalVerification();
        $this->accountingReferenceColumns();
        $this->seedPermissions();
    }

    private function settingsAndMasters(): void
    {
        if (!Schema::hasTable('fixed_asset_settings')) {
            Schema::create('fixed_asset_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('default_depreciation_method', 40)->default('straight_line');
                $table->string('depreciation_posting_frequency', 30)->default('monthly');
                $table->string('depreciation_start_rule', 40)->default('capitalization_date');
                $table->boolean('allow_backdated_capitalization')->default(false);
                $table->boolean('allow_manual_depreciation_override')->default(false);
                $table->boolean('require_asset_tag')->default(true);
                $table->boolean('auto_generate_asset_tag')->default(true);
                $table->boolean('require_asset_verification')->default(false);
                $table->unsignedBigInteger('default_asset_clearing_account_id')->nullable()->index();
                $table->unsignedBigInteger('default_asset_disposal_account_id')->nullable()->index();
                $table->unsignedBigInteger('default_profit_on_sale_account_id')->nullable()->index();
                $table->unsignedBigInteger('default_loss_on_sale_account_id')->nullable()->index();
                $table->unsignedBigInteger('default_impairment_loss_account_id')->nullable()->index();
                $table->unsignedBigInteger('default_accumulated_impairment_account_id')->nullable()->index();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique('business_id');
            });
        }

        if (!Schema::hasTable('asset_categories')) {
            Schema::create('asset_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('category_code', 50);
                $table->string('category_name');
                $table->foreignId('parent_id')->nullable()->constrained('asset_categories')->nullOnDelete();
                $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
                $table->foreignId('accumulated_depreciation_account_id')->constrained('accounts')->restrictOnDelete();
                $table->foreignId('depreciation_expense_account_id')->constrained('accounts')->restrictOnDelete();
                $table->string('default_depreciation_method', 40)->default('straight_line');
                $table->integer('default_useful_life_months')->nullable();
                $table->decimal('default_depreciation_rate', 8, 4)->nullable();
                $table->decimal('default_residual_value_percent', 8, 4)->default(0);
                $table->decimal('capitalisation_threshold', 15, 2)->nullable();
                $table->unsignedBigInteger('maintenance_expense_account_id')->nullable()->index();
                $table->unsignedBigInteger('impairment_loss_account_id')->nullable()->index();
                $table->unsignedBigInteger('profit_on_sale_account_id')->nullable()->index();
                $table->unsignedBigInteger('loss_on_sale_account_id')->nullable()->index();
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_system')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'category_code']);
            });
        }

        if (!Schema::hasTable('asset_locations')) {
            Schema::create('asset_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->string('location_code', 50);
                $table->string('location_name');
                $table->string('location_type', 40)->default('office')->index();
                $table->string('floor')->nullable();
                $table->string('room')->nullable();
                $table->string('rack')->nullable();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'location_code']);
            });
        }
    }

    private function registerAndAcquisition(): void
    {
        if (!Schema::hasTable('fixed_assets')) {
            Schema::create('fixed_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
                $table->string('asset_number', 50);
                $table->string('asset_tag', 100);
                $table->string('asset_name');
                $table->text('description')->nullable();
                $table->string('manufacturer')->nullable();
                $table->string('model_number')->nullable();
                $table->string('serial_number')->nullable()->index();
                $table->string('barcode')->nullable()->index();
                $table->string('qr_code')->nullable();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->unsignedBigInteger('purchase_invoice_id')->nullable()->index();
                $table->unsignedBigInteger('purchase_invoice_item_id')->nullable()->index();
                $table->date('acquisition_date');
                $table->date('capitalization_date')->nullable()->index();
                $table->date('put_to_use_date')->nullable();
                $table->decimal('purchase_cost', 15, 2);
                $table->decimal('additional_cost', 15, 2)->default(0);
                $table->decimal('capitalized_cost', 15, 2);
                $table->decimal('residual_value', 15, 2)->default(0);
                $table->decimal('depreciable_amount', 15, 2)->default(0);
                $table->string('depreciation_method', 40)->default('straight_line');
                $table->integer('useful_life_months')->nullable();
                $table->decimal('depreciation_rate', 8, 4)->nullable();
                $table->decimal('accumulated_depreciation', 15, 2)->default(0);
                $table->decimal('accumulated_impairment', 15, 2)->default(0);
                $table->decimal('net_book_value', 15, 2)->default(0);
                $table->foreignId('current_location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->unsignedBigInteger('assigned_employee_id')->nullable()->index();
                $table->unsignedBigInteger('cost_center_id')->nullable()->index();
                $table->unsignedBigInteger('department_id')->nullable()->index();
                $table->string('ownership_type', 30)->default('owned');
                $table->string('condition_status', 40)->default('new')->index();
                $table->string('asset_status', 40)->default('draft')->index();
                $table->date('warranty_start_date')->nullable();
                $table->date('warranty_end_date')->nullable()->index();
                $table->unsignedBigInteger('insurance_policy_id')->nullable()->index();
                $table->date('expected_disposal_date')->nullable();
                $table->date('disposal_date')->nullable();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'asset_number']);
                $table->unique(['business_id', 'asset_tag']);
                $table->index(['business_id', 'asset_category_id']);
                $table->index(['business_id', 'branch_id']);
                $table->index(['business_id', 'asset_status']);
                $table->index(['business_id', 'current_location_id']);
            });
        }

        if (!Schema::hasTable('fixed_asset_components')) {
            Schema::create('fixed_asset_components', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->string('component_name');
                $table->string('component_code', 50);
                $table->text('description')->nullable();
                $table->date('capitalization_date');
                $table->decimal('component_cost', 15, 2);
                $table->decimal('residual_value', 15, 2)->default(0);
                $table->string('depreciation_method', 40)->default('straight_line');
                $table->integer('useful_life_months')->nullable();
                $table->decimal('depreciation_rate', 8, 4)->nullable();
                $table->decimal('accumulated_depreciation', 15, 2)->default(0);
                $table->decimal('net_book_value', 15, 2)->default(0);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique(['fixed_asset_id', 'component_code']);
            });
        }

        if (!Schema::hasTable('asset_acquisitions')) {
            Schema::create('asset_acquisitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('acquisition_number', 50);
                $table->date('acquisition_date');
                $table->string('source_type', 40)->default('manual');
                $table->unsignedBigInteger('source_id')->nullable()->index();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->string('invoice_number')->nullable();
                $table->date('invoice_date')->nullable();
                $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
                $table->integer('quantity')->default(1);
                $table->decimal('base_cost', 15, 2);
                $table->decimal('additional_cost', 15, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->boolean('input_tax_credit_eligible')->default(true);
                $table->decimal('non_creditable_tax_amount', 15, 2)->default(0);
                $table->decimal('total_capitalizable_cost', 15, 2);
                $table->string('payment_status', 30)->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->text('narration')->nullable();
                $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'acquisition_number']);
            });
        }

        if (!Schema::hasTable('asset_capitalizations')) {
            Schema::create('asset_capitalizations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('capitalization_number', 50);
                $table->date('capitalization_date');
                $table->string('source_type', 40);
                $table->unsignedBigInteger('source_id')->index();
                $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->nullOnDelete();
                $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
                $table->decimal('capitalized_amount', 15, 2);
                $table->date('put_to_use_date')->nullable();
                $table->foreignId('asset_location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->unsignedBigInteger('assigned_employee_id')->nullable()->index();
                $table->string('status', 30)->default('draft')->index();
                $table->text('narration')->nullable();
                $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'capitalization_number']);
            });
        }
    }

    private function depreciationAndMovement(): void
    {
        if (!Schema::hasTable('depreciation_runs')) {
            Schema::create('depreciation_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('run_number', 50);
                $table->string('financial_year', 20);
                $table->date('period_start')->index();
                $table->date('period_end');
                $table->date('posting_date');
                $table->integer('total_assets')->default(0);
                $table->integer('processed_assets')->default(0);
                $table->integer('skipped_assets')->default(0);
                $table->integer('failed_assets')->default(0);
                $table->decimal('total_depreciation', 15, 2)->default(0);
                $table->string('status', 30)->default('draft')->index();
                $table->json('error_summary_json')->nullable();
                $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'run_number']);
                $table->index(['business_id', 'period_start', 'period_end']);
            });
        }

        if (!Schema::hasTable('asset_depreciation_schedules')) {
            Schema::create('asset_depreciation_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->foreignId('asset_component_id')->nullable()->constrained('fixed_asset_components')->cascadeOnDelete();
                $table->string('financial_year', 20);
                $table->date('period_start');
                $table->date('period_end');
                $table->decimal('opening_gross_value', 15, 2)->default(0);
                $table->decimal('opening_accumulated_depreciation', 15, 2)->default(0);
                $table->decimal('depreciation_amount', 15, 2)->default(0);
                $table->decimal('adjustment_amount', 15, 2)->default(0);
                $table->decimal('closing_accumulated_depreciation', 15, 2)->default(0);
                $table->decimal('closing_net_book_value', 15, 2)->default(0);
                $table->string('status', 30)->default('projected')->index();
                $table->foreignId('depreciation_run_id')->nullable()->constrained('depreciation_runs')->nullOnDelete();
                $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->timestamp('calculated_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();
                $table->unique(['fixed_asset_id', 'asset_component_id', 'period_start', 'period_end'], 'asset_depn_unique_period');
                $table->index(['fixed_asset_id', 'period_start', 'period_end']);
            });
        }

        if (!Schema::hasTable('asset_assignments')) {
            Schema::create('asset_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->unsignedBigInteger('assigned_to_employee_id')->nullable()->index();
                $table->unsignedBigInteger('assigned_to_department_id')->nullable()->index();
                $table->foreignId('assigned_location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->date('assignment_date');
                $table->date('expected_return_date')->nullable();
                $table->date('actual_return_date')->nullable();
                $table->string('condition_at_issue')->nullable();
                $table->string('condition_at_return')->nullable();
                $table->text('issue_notes')->nullable();
                $table->text('return_notes')->nullable();
                $table->string('status', 30)->default('assigned')->index();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['fixed_asset_id', 'status']);
            });
        }

        if (!Schema::hasTable('asset_transfer_vouchers')) {
            Schema::create('asset_transfer_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('transfer_number', 50);
                $table->date('transfer_date');
                $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
                $table->foreignId('source_location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->foreignId('destination_location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->string('transfer_type', 50)->default('location_transfer');
                $table->string('status', 30)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'transfer_number']);
            });
        }

        if (!Schema::hasTable('asset_transfer_items')) {
            Schema::create('asset_transfer_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asset_transfer_voucher_id')->constrained('asset_transfer_vouchers')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
                $table->unsignedBigInteger('source_employee_id')->nullable()->index();
                $table->unsignedBigInteger('destination_employee_id')->nullable()->index();
                $table->string('condition_before')->nullable();
                $table->string('condition_after')->nullable();
                $table->text('dispatch_notes')->nullable();
                $table->text('receipt_notes')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->timestamps();
                $table->index('fixed_asset_id');
            });
        }
    }

    private function maintenanceAndProtection(): void
    {
        if (!Schema::hasTable('asset_maintenance_requests')) {
            Schema::create('asset_maintenance_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->string('request_number', 50);
                $table->date('request_date');
                $table->string('maintenance_type', 40);
                $table->text('issue_description');
                $table->string('priority', 20)->default('medium');
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('assigned_vendor_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $table->unsignedBigInteger('assigned_employee_id')->nullable()->index();
                $table->date('expected_start_date')->nullable();
                $table->date('expected_completion_date')->nullable()->index();
                $table->date('actual_start_date')->nullable();
                $table->date('actual_completion_date')->nullable();
                $table->decimal('estimated_cost', 15, 2)->nullable();
                $table->decimal('actual_cost', 15, 2)->default(0);
                $table->decimal('downtime_hours', 15, 2)->default(0);
                $table->string('status', 30)->default('open')->index();
                $table->text('resolution_notes')->nullable();
                $table->unsignedBigInteger('expense_voucher_id')->nullable()->index();
                $table->timestamps();
                $table->unique(['business_id', 'request_number']);
                $table->index(['fixed_asset_id', 'status']);
            });
        }

        if (!Schema::hasTable('asset_maintenance_schedules')) {
            Schema::create('asset_maintenance_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->string('schedule_name');
                $table->string('frequency_type', 30);
                $table->integer('frequency_value')->default(1);
                $table->date('last_service_date')->nullable();
                $table->date('next_service_date')->index();
                $table->decimal('trigger_meter_value', 15, 3)->nullable();
                $table->decimal('current_meter_value', 15, 3)->nullable();
                $table->boolean('auto_create_request')->default(true);
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('asset_warranties')) {
            Schema::create('asset_warranties', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->string('warranty_provider');
                $table->string('warranty_type');
                $table->string('warranty_number')->nullable();
                $table->date('start_date');
                $table->date('end_date')->index();
                $table->text('coverage_details')->nullable();
                $table->text('exclusions')->nullable();
                $table->string('contact_person')->nullable();
                $table->string('contact_number')->nullable();
                $table->string('email')->nullable();
                $table->unsignedBigInteger('attachment_id')->nullable()->index();
                $table->string('status', 30)->default('active')->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('asset_insurance_policies')) {
            Schema::create('asset_insurance_policies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('policy_number', 80);
                $table->string('insurer_name');
                $table->string('policy_type', 40);
                $table->date('start_date');
                $table->date('end_date')->index();
                $table->decimal('insured_value', 15, 2);
                $table->decimal('premium_amount', 15, 2);
                $table->decimal('deductible_amount', 15, 2)->nullable();
                $table->string('policy_document_path')->nullable();
                $table->string('status', 30)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'policy_number']);
            });
        }

        if (!Schema::hasTable('asset_insurance_policy_items')) {
            Schema::create('asset_insurance_policy_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asset_insurance_policy_id')->constrained('asset_insurance_policies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->decimal('insured_value', 15, 2);
                $table->text('remarks')->nullable();
                $table->timestamps();
                $table->unique(['asset_insurance_policy_id', 'fixed_asset_id'], 'asset_policy_item_unique');
            });
        }

        if (!Schema::hasTable('asset_meter_readings')) {
            Schema::create('asset_meter_readings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();
                $table->string('meter_type', 40);
                $table->date('reading_date');
                $table->decimal('reading_value', 15, 3);
                $table->string('unit', 30);
                $table->text('remarks')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['fixed_asset_id', 'reading_date']);
            });
        }
    }

    private function valuationDisposalVerification(): void
    {
        if (!Schema::hasTable('asset_revaluation_vouchers')) {
            Schema::create('asset_revaluation_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('revaluation_number', 50);
                $table->date('revaluation_date');
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
                $table->decimal('previous_gross_value', 15, 2);
                $table->decimal('previous_accumulated_depreciation', 15, 2);
                $table->decimal('previous_net_book_value', 15, 2);
                $table->decimal('revalued_amount', 15, 2);
                $table->decimal('revaluation_difference', 15, 2);
                $table->string('revaluation_type', 30);
                $table->string('valuation_reference')->nullable();
                $table->string('valuer_name')->nullable();
                $table->text('remarks')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'revaluation_number']);
            });
        }

        if (!Schema::hasTable('asset_impairment_vouchers')) {
            Schema::create('asset_impairment_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('impairment_number', 50);
                $table->date('impairment_date');
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
                $table->decimal('carrying_amount_before', 15, 2);
                $table->decimal('recoverable_amount', 15, 2);
                $table->decimal('impairment_loss', 15, 2);
                $table->text('impairment_reason');
                $table->string('valuation_reference')->nullable();
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'impairment_number']);
            });
        }

        if (!Schema::hasTable('asset_disposal_vouchers')) {
            Schema::create('asset_disposal_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('disposal_number', 50);
                $table->date('disposal_date');
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
                $table->string('disposal_type', 30);
                $table->foreignId('buyer_customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('buyer_name')->nullable();
                $table->unsignedBigInteger('sale_invoice_id')->nullable()->index();
                $table->decimal('sale_value', 15, 2)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->decimal('disposal_expense', 15, 2)->default(0);
                $table->decimal('gross_book_value', 15, 2);
                $table->decimal('accumulated_depreciation', 15, 2);
                $table->decimal('accumulated_impairment', 15, 2);
                $table->decimal('net_book_value', 15, 2);
                $table->decimal('profit_or_loss', 15, 2);
                $table->text('reason');
                $table->string('status', 30)->default('draft')->index();
                $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'disposal_number']);
            });
        }

        if (!Schema::hasTable('asset_verification_sessions')) {
            Schema::create('asset_verification_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
                $table->string('session_number', 50);
                $table->date('verification_date');
                $table->foreignId('location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
                $table->string('status', 30)->default('draft')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'session_number']);
            });
        }

        if (!Schema::hasTable('asset_verification_items')) {
            Schema::create('asset_verification_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asset_verification_session_id')->constrained('asset_verification_sessions')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
                $table->foreignId('expected_location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->foreignId('found_location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
                $table->unsignedBigInteger('expected_employee_id')->nullable()->index();
                $table->unsignedBigInteger('found_employee_id')->nullable()->index();
                $table->string('verification_status', 40)->default('not_verified')->index();
                $table->string('condition_status')->nullable();
                $table->timestamp('scanned_at')->nullable();
                $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['fixed_asset_id', 'verification_status']);
            });
        }

        if (!Schema::hasTable('asset_attachments')) {
            Schema::create('asset_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('fixed_asset_id')->nullable()->constrained('fixed_assets')->cascadeOnDelete();
                $table->string('related_type')->nullable();
                $table->unsignedBigInteger('related_id')->nullable();
                $table->string('document_type')->default('other');
                $table->string('file_name');
                $table->string('original_name');
                $table->string('file_path');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'related_type', 'related_id'], 'asset_attachments_related_index');
            });
        }
    }

    private function accountingReferenceColumns(): void
    {
        if (Schema::hasTable('journal_entries') && !Schema::hasColumn('journal_entries', 'fixed_asset_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->unsignedBigInteger('fixed_asset_id')->nullable()->after('supplier_id')->index();
            });
        }
    }

    private function seedPermissions(): void
    {
        if (Schema::hasTable('fixed_asset_settings')) {
            $businessIds = Schema::hasTable('companies') ? DB::table('companies')->pluck('id') : collect([1]);
            foreach ($businessIds as $businessId) {
                DB::table('fixed_asset_settings')->updateOrInsert(
                    ['business_id' => $businessId],
                    ['default_depreciation_method' => 'straight_line', 'depreciation_posting_frequency' => 'monthly', 'depreciation_start_rule' => 'capitalization_date', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) return;
        $names = ['view asset categories','manage asset categories','view assets','create asset','edit draft asset','approve asset','capitalize asset','view asset cost','view depreciation','calculate depreciation','approve depreciation','post depreciation','reverse depreciation','override depreciation','assign asset','return asset','transfer asset','receive asset transfer','manage maintenance','approve maintenance','view warranty','manage warranty','view insurance','manage insurance','record meter reading','revalue asset','impair asset','dispose asset','write off asset','reopen disposed asset','perform asset verification','review asset verification','approve verification corrections','print asset labels','import opening assets','export asset reports','view fixed asset dashboard','view fixed asset reports','view asset accounting reconciliation','manage fixed asset settings','manage asset locations'];
        foreach ($names as $name) DB::table('permissions')->updateOrInsert(['name' => $name], ['module' => 'fixed_assets', 'description' => ucfirst($name), 'created_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        foreach ([1, 2] as $roleId) foreach ($ids as $id) DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Fixed asset records are retained intentionally for audit and statutory register history.
    }
};
