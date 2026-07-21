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
        $this->leads();
        $this->teamsAndPipelines();
        $this->opportunities();
        $this->activities();
        $this->analytics();
        $this->seedDefaults();
    }

    private function settingsAndMasters(): void
    {
        if (!Schema::hasTable('crm_settings')) {
            Schema::create('crm_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->unsignedBigInteger('default_lead_owner_id')->nullable()->index();
                $table->unsignedBigInteger('default_pipeline_id')->nullable()->index();
                $table->boolean('auto_assign_leads')->default(false);
                $table->string('assignment_method', 30)->nullable();
                $table->boolean('require_lead_source')->default(false);
                $table->boolean('require_lost_reason')->default(true);
                $table->boolean('duplicate_check_enabled')->default(true);
                $table->json('duplicate_check_fields_json')->nullable();
                $table->integer('default_follow_up_days')->nullable();
                $table->boolean('overdue_reminder_enabled')->default(true);
                $table->boolean('lead_conversion_requires_approval')->default(false);
                $table->boolean('allow_multiple_opportunities_per_lead')->default(true);
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique('business_id');
            });
        }

        if (!Schema::hasTable('lead_sources')) {
            Schema::create('lead_sources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('source_code', 50);
                $table->string('source_name');
                $table->string('source_type', 30)->index();
                $table->foreignId('parent_id')->nullable()->constrained('lead_sources')->nullOnDelete();
                $table->boolean('cost_tracking_enabled')->default(false);
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_system')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'source_code']);
            });
        }

        if (!Schema::hasTable('lead_statuses')) {
            Schema::create('lead_statuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('status_code', 50);
                $table->string('status_name');
                $table->string('status_category', 30)->index();
                $table->integer('display_order')->default(0);
                $table->string('color_code', 20)->nullable();
                $table->boolean('is_initial')->default(false);
                $table->boolean('is_converted')->default(false);
                $table->boolean('is_lost')->default(false);
                $table->boolean('is_system')->default(false);
                $table->boolean('active')->default(true)->index();
                $table->timestamps();
                $table->unique(['business_id', 'status_code']);
            });
        }

        if (!Schema::hasTable('crm_lost_reasons')) {
            Schema::create('crm_lost_reasons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('reason_code', 50);
                $table->string('reason_name');
                $table->string('applicable_to', 30)->default('all')->index();
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_system')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'reason_code']);
            });
        }

        if (!Schema::hasTable('campaigns')) {
            Schema::create('campaigns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('campaign_code', 50);
                $table->string('campaign_name');
                $table->string('campaign_type', 30)->index();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->decimal('budget_amount', 15, 2)->nullable();
                $table->decimal('actual_cost', 15, 2)->default(0);
                $table->integer('target_leads')->nullable();
                $table->decimal('target_revenue', 15, 2)->nullable();
                $table->unsignedBigInteger('owner_id')->nullable()->index();
                $table->string('status', 20)->default('active')->index();
                $table->text('description')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'campaign_code']);
            });
        }
    }

    private function leads(): void
    {
        if (!Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('lead_number', 50);
                $table->string('lead_type', 30)->default('individual');
                $table->string('company_name')->nullable();
                $table->string('contact_person_name');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('email')->nullable()->index();
                $table->string('mobile', 30)->index();
                $table->string('alternate_mobile', 30)->nullable();
                $table->string('whatsapp_number', 30)->nullable();
                $table->string('website')->nullable();
                $table->string('designation')->nullable();
                $table->unsignedBigInteger('industry_id')->nullable()->index();
                $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
                $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
                $table->unsignedBigInteger('assigned_team_id')->nullable()->index();
                $table->foreignId('status_id')->constrained('lead_statuses')->restrictOnDelete();
                $table->string('qualification_status', 40)->nullable()->index();
                $table->string('priority', 20)->default('medium')->index();
                $table->decimal('estimated_value', 15, 2)->nullable();
                $table->date('expected_closing_date')->nullable();
                $table->json('preferred_product_ids_json')->nullable();
                $table->text('requirement_summary')->nullable();
                $table->json('billing_address_json')->nullable();
                $table->json('shipping_address_json')->nullable();
                $table->string('city', 100)->nullable();
                $table->unsignedBigInteger('state_id')->nullable()->index();
                $table->string('pincode', 20)->nullable();
                $table->unsignedBigInteger('country_id')->nullable()->index();
                $table->string('gstin', 30)->nullable()->index();
                $table->string('pan', 20)->nullable()->index();
                $table->string('referral_name')->nullable();
                $table->string('referral_contact')->nullable();
                $table->boolean('do_not_call')->default(false);
                $table->boolean('do_not_email')->default(false);
                $table->boolean('do_not_whatsapp')->default(false);
                $table->string('consent_source')->nullable();
                $table->timestamp('consent_at')->nullable();
                $table->text('consent_notes')->nullable();
                $table->string('conversion_status', 40)->default('not_converted')->index();
                $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->unsignedBigInteger('converted_contact_id')->nullable()->index();
                $table->unsignedBigInteger('converted_opportunity_id')->nullable()->index();
                $table->timestamp('converted_at')->nullable();
                $table->foreignId('lost_reason_id')->nullable()->constrained('crm_lost_reasons')->nullOnDelete();
                $table->text('lost_notes')->nullable();
                $table->timestamp('last_activity_at')->nullable()->index();
                $table->timestamp('next_follow_up_at')->nullable()->index();
                $table->integer('score')->default(0);
                $table->json('tags_json')->nullable();
                $table->json('custom_fields_json')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'lead_number']);
                $table->index(['business_id', 'status_id']);
                $table->index(['business_id', 'assigned_to']);
                $table->index(['business_id', 'branch_id']);
                $table->index(['business_id', 'lead_source_id']);
            });
        }

        if (!Schema::hasTable('lead_contacts')) {
            Schema::create('lead_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->string('contact_name');
                $table->string('designation')->nullable();
                $table->string('email')->nullable();
                $table->string('mobile', 30)->nullable();
                $table->string('whatsapp_number', 30)->nullable();
                $table->boolean('is_primary')->default(false);
                $table->boolean('do_not_call')->default(false);
                $table->boolean('do_not_email')->default(false);
                $table->boolean('do_not_whatsapp')->default(false);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['lead_id', 'is_primary']);
            });
        }

        if (!Schema::hasTable('lead_assignments')) {
            Schema::create('lead_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->unsignedBigInteger('assigned_from')->nullable()->index();
                $table->unsignedBigInteger('assigned_to')->index();
                $table->unsignedBigInteger('assigned_team_id')->nullable()->index();
                $table->string('assignment_method', 30);
                $table->text('assignment_reason')->nullable();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->useCurrent();
                $table->timestamp('unassigned_at')->nullable();
                $table->timestamps();
                $table->index(['lead_id', 'assigned_at']);
            });
        }
    }

    private function teamsAndPipelines(): void
    {
        if (!Schema::hasTable('sales_teams')) {
            Schema::create('sales_teams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('team_name');
                $table->string('team_code', 50);
                $table->unsignedBigInteger('manager_id')->index();
                $table->decimal('target_amount', 15, 2)->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'team_code']);
            });
        }

        if (!Schema::hasTable('sales_team_members')) {
            Schema::create('sales_team_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_team_id')->constrained('sales_teams')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('role_in_team', 30);
                $table->decimal('target_amount', 15, 2)->nullable();
                $table->date('active_from');
                $table->date('active_to')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique(['sales_team_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('sales_pipelines')) {
            Schema::create('sales_pipelines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('pipeline_name');
                $table->string('pipeline_code', 50);
                $table->text('description')->nullable();
                $table->boolean('is_default')->default(false)->index();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'pipeline_code']);
            });
        }

        if (!Schema::hasTable('pipeline_stages')) {
            Schema::create('pipeline_stages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_pipeline_id')->constrained('sales_pipelines')->cascadeOnDelete();
                $table->string('stage_name');
                $table->string('stage_code', 50);
                $table->integer('stage_order')->default(0);
                $table->decimal('probability_percent', 5, 2)->default(0);
                $table->integer('expected_days')->nullable();
                $table->boolean('is_won')->default(false);
                $table->boolean('is_lost')->default(false);
                $table->string('color_code', 20)->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique(['sales_pipeline_id', 'stage_code']);
            });
        }
    }

    private function opportunities(): void
    {
        if (!Schema::hasTable('opportunities')) {
            Schema::create('opportunities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('opportunity_number', 50);
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->unsignedBigInteger('contact_id')->nullable()->index();
                $table->foreignId('pipeline_id')->constrained('sales_pipelines')->restrictOnDelete();
                $table->foreignId('stage_id')->constrained('pipeline_stages')->restrictOnDelete();
                $table->string('opportunity_name');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('owner_id')->index();
                $table->foreignId('sales_team_id')->nullable()->constrained('sales_teams')->nullOnDelete();
                $table->foreignId('source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
                $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
                $table->unsignedBigInteger('currency_id')->nullable()->index();
                $table->decimal('estimated_value', 15, 2)->default(0);
                $table->decimal('weighted_value', 15, 2)->default(0);
                $table->decimal('probability_percent', 5, 2)->default(0);
                $table->date('expected_closing_date')->nullable()->index();
                $table->date('actual_closing_date')->nullable();
                $table->string('priority', 20)->default('medium')->index();
                $table->string('competitor_name')->nullable();
                $table->text('next_step')->nullable();
                $table->timestamp('next_follow_up_at')->nullable();
                $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
                $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
                $table->text('won_reason')->nullable();
                $table->foreignId('lost_reason_id')->nullable()->constrained('crm_lost_reasons')->nullOnDelete();
                $table->text('lost_notes')->nullable();
                $table->string('status', 30)->default('open')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['business_id', 'opportunity_number']);
                $table->index(['business_id', 'stage_id']);
                $table->index(['business_id', 'owner_id']);
            });
        }

        if (!Schema::hasTable('opportunity_items')) {
            Schema::create('opportunity_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variant_items')->nullOnDelete();
                $table->string('description');
                $table->decimal('quantity', 15, 3)->default(1);
                $table->decimal('estimated_unit_price', 15, 2)->default(0);
                $table->decimal('estimated_discount', 15, 2)->default(0);
                $table->decimal('estimated_tax', 15, 2)->default(0);
                $table->decimal('estimated_total', 15, 2)->default(0);
                $table->decimal('probability_percent', 5, 2)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    private function activities(): void
    {
        if (!Schema::hasTable('crm_activities')) {
            Schema::create('crm_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('activity_number', 50)->nullable();
                $table->string('activity_type', 40)->index();
                $table->string('subject');
                $table->text('description')->nullable();
                $table->string('related_type');
                $table->unsignedBigInteger('related_id');
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->foreignId('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->unsignedBigInteger('contact_id')->nullable()->index();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->date('activity_date')->index();
                $table->timestamp('start_at')->nullable();
                $table->timestamp('end_at')->nullable();
                $table->integer('duration_minutes')->nullable();
                $table->string('direction', 20)->nullable();
                $table->text('outcome')->nullable();
                $table->text('next_action')->nullable();
                $table->timestamp('next_follow_up_at')->nullable();
                $table->string('status', 30)->default('planned')->index();
                $table->string('priority', 20)->nullable();
                $table->string('location')->nullable();
                $table->string('meeting_mode', 30)->nullable();
                $table->string('external_reference')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'related_type', 'related_id'], 'crm_activities_related_index');
                $table->index(['assigned_to', 'activity_date'], 'crm_activities_user_date_index');
                $table->index(['status', 'next_follow_up_at'], 'crm_activities_status_followup_index');
            });
        }

        if (!Schema::hasTable('crm_notes')) {
            Schema::create('crm_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('related_type');
                $table->unsignedBigInteger('related_id');
                $table->text('note_text');
                $table->boolean('is_pinned')->default(false);
                $table->string('visibility', 20)->default('team');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['business_id', 'related_type', 'related_id'], 'crm_notes_related_index');
            });
        }

        if (!Schema::hasTable('crm_attachments')) {
            Schema::create('crm_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('related_type');
                $table->unsignedBigInteger('related_id');
                $table->string('file_name');
                $table->string('original_name');
                $table->string('file_path');
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('document_type')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'related_type', 'related_id'], 'crm_attachments_related_index');
            });
        }

        if (!Schema::hasTable('crm_reminders')) {
            Schema::create('crm_reminders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('activity_id')->nullable()->constrained('crm_activities')->cascadeOnDelete();
                $table->string('related_type');
                $table->unsignedBigInteger('related_id');
                $table->unsignedBigInteger('user_id')->index();
                $table->timestamp('reminder_at')->index();
                $table->string('reminder_channel', 30)->default('in_app');
                $table->string('status', 30)->default('pending')->index();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'reminder_at', 'status'], 'crm_reminders_user_due_index');
            });
        }
    }

    private function analytics(): void
    {
        if (!Schema::hasTable('lead_qualifications')) {
            Schema::create('lead_qualifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->string('budget_status')->nullable();
                $table->string('authority_status')->nullable();
                $table->string('need_status')->nullable();
                $table->string('timeline_status')->nullable();
                $table->decimal('budget_amount', 15, 2)->nullable();
                $table->string('decision_maker_name')->nullable();
                $table->date('expected_purchase_date')->nullable();
                $table->text('pain_points')->nullable();
                $table->text('requirement_details')->nullable();
                $table->text('competitor_details')->nullable();
                $table->integer('qualification_score')->default(0);
                $table->string('qualification_status', 40)->default('unqualified')->index();
                $table->foreignId('qualified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('qualified_at')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'lead_id']);
            });
        }

        if (!Schema::hasTable('lead_scoring_rules')) {
            Schema::create('lead_scoring_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('rule_name');
                $table->string('event_type', 80)->index();
                $table->json('condition_json')->nullable();
                $table->integer('score_change')->default(0);
                $table->integer('maximum_occurrences')->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('lead_score_logs')) {
            Schema::create('lead_score_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('scoring_rule_id')->nullable()->constrained('lead_scoring_rules')->nullOnDelete();
                $table->string('event_type', 80);
                $table->integer('score_change');
                $table->integer('previous_score');
                $table->integer('new_score');
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('lead_imports')) {
            Schema::create('lead_imports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->string('file_name');
                $table->integer('total_rows')->default(0);
                $table->integer('imported_rows')->default(0);
                $table->integer('duplicate_rows')->default(0);
                $table->integer('failed_rows')->default(0);
                $table->string('status', 30)->default('pending')->index();
                $table->json('mapping_json')->nullable();
                $table->string('error_file_path')->nullable();
                $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('sales_targets')) {
            Schema::create('sales_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained('companies')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('target_type', 20)->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->foreignId('sales_team_id')->nullable()->constrained('sales_teams')->nullOnDelete();
                $table->string('financial_year', 20);
                $table->string('period_type', 20);
                $table->date('period_start');
                $table->date('period_end');
                $table->integer('target_leads')->nullable();
                $table->integer('target_qualified_leads')->nullable();
                $table->integer('target_opportunities')->nullable();
                $table->decimal('target_quotation_value', 15, 2)->nullable();
                $table->decimal('target_order_value', 15, 2)->nullable();
                $table->decimal('target_invoice_value', 15, 2)->nullable();
                $table->decimal('target_collection_value', 15, 2)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'target_type', 'period_start', 'period_end'], 'sales_targets_period_index');
            });
        }
    }

    private function seedDefaults(): void
    {
        $businessIds = Schema::hasTable('companies') ? DB::table('companies')->pluck('id') : collect([1]);
        foreach ($businessIds as $businessId) {
            DB::table('crm_settings')->updateOrInsert(
                ['business_id' => $businessId],
                ['assignment_method' => 'manual', 'duplicate_check_fields_json' => json_encode(['mobile', 'email', 'gstin']), 'default_follow_up_days' => 2, 'updated_at' => now(), 'created_at' => now()]
            );

            foreach ([['WEB','Website','organic'],['PHONE','Phone Call','direct'],['WHATSAPP','WhatsApp','direct'],['EMAIL','Email','direct'],['WALKIN','Walk-In','offline'],['REF','Referral','referral'],['EXISTING','Existing Customer','internal'],['FB','Facebook','paid'],['INSTA','Instagram','paid'],['GOOGLE','Google Ads','paid'],['MARKET','Marketplace','partner'],['TRADE','Trade Show','offline'],['PARTNER','Partner','partner'],['COLD','Cold Call','direct'],['OTHER','Other','direct']] as $source) {
                DB::table('lead_sources')->updateOrInsert(['business_id' => $businessId, 'source_code' => $source[0]], ['source_name' => $source[1], 'source_type' => $source[2], 'status' => 'active', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
            }

            foreach ([['NEW','New','open',1,'#2563eb',1,0,0],['ASSIGNED','Assigned','open',2,'#0f766e',0,0,0],['CONTACTED','Contacted','contacted',3,'#7c3aed',0,0,0],['FOLLOWUP','Follow-up','contacted',4,'#ea580c',0,0,0],['QUALIFIED','Qualified','qualified',5,'#16a34a',0,0,0],['PROPOSAL','Proposal Sent','qualified',6,'#0891b2',0,0,0],['NEGOTIATION','Negotiation','qualified',7,'#ca8a04',0,0,0],['CONVERTED','Converted','converted',8,'#15803d',0,1,0],['NOT_INTERESTED','Not Interested','unqualified',9,'#6b7280',0,0,1],['LOST','Lost','lost',10,'#dc2626',0,0,1],['INVALID','Invalid','unqualified',11,'#991b1b',0,0,1]] as $status) {
                DB::table('lead_statuses')->updateOrInsert(['business_id' => $businessId, 'status_code' => $status[0]], ['status_name' => $status[1], 'status_category' => $status[2], 'display_order' => $status[3], 'color_code' => $status[4], 'is_initial' => $status[5], 'is_converted' => $status[6], 'is_lost' => $status[7], 'is_system' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }

            $pipelineId = DB::table('sales_pipelines')->updateOrInsert(['business_id' => $businessId, 'pipeline_code' => 'DEFAULT'], ['pipeline_name' => 'Default Pipeline', 'description' => 'Standard CRM sales pipeline', 'is_default' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $pipeline = DB::table('sales_pipelines')->where('business_id', $businessId)->where('pipeline_code', 'DEFAULT')->value('id');
            foreach ([['NEW','New Opportunity',1,10,0,0,'#2563eb'],['REQ','Requirement Analysis',2,25,0,0,'#7c3aed'],['DEMO_SCHEDULED','Demo Scheduled',3,35,0,0,'#0891b2'],['DEMO_DONE','Demo Completed',4,45,0,0,'#0d9488'],['PROPOSAL','Proposal Sent',5,60,0,0,'#ca8a04'],['NEGOTIATION','Negotiation',6,75,0,0,'#ea580c'],['VERBAL','Verbal Confirmation',7,90,0,0,'#16a34a'],['WON','Won',8,100,1,0,'#15803d'],['LOST','Lost',9,0,0,1,'#dc2626']] as $stage) {
                DB::table('pipeline_stages')->updateOrInsert(['sales_pipeline_id' => $pipeline, 'stage_code' => $stage[0]], ['stage_name' => $stage[1], 'stage_order' => $stage[2], 'probability_percent' => $stage[3], 'is_won' => $stage[4], 'is_lost' => $stage[5], 'color_code' => $stage[6], 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('crm_settings')->where('business_id', $businessId)->update(['default_pipeline_id' => $pipeline]);

            foreach ([['PRICE','Price too high'],['COMPETITOR','Competitor selected'],['BUDGET','No budget'],['NO_RESPONSE','No response'],['CANCELLED','Requirement cancelled'],['UNAVAILABLE','Product unavailable'],['DELIVERY','Delivery timeline'],['POOR_FIT','Poor fit'],['INVALID','Invalid lead'],['DUPLICATE','Duplicate lead'],['ELSEWHERE','Purchased elsewhere'],['INTERNAL_DELAY','Internal delay'],['OTHER','Other']] as $reason) {
                DB::table('crm_lost_reasons')->updateOrInsert(['business_id' => $businessId, 'reason_code' => $reason[0]], ['reason_name' => $reason[1], 'applicable_to' => 'all', 'status' => 'active', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) return;
        $names = ['view leads','create lead','edit lead','delete draft lead','assign lead','reassign lead','bulk assign lead','import leads','export leads','merge duplicate leads','qualify lead','disqualify lead','convert lead','reopen converted lead','view all branch leads','view team leads','view opportunities','create opportunity','edit opportunity','change opportunity stage','mark opportunity won','mark opportunity lost','reopen opportunity','view pipeline values','view profit-related CRM data','create CRM activity','assign CRM task','complete CRM activity','edit completed activity','delete CRM activity','view private notes','manage sales pipelines','manage lead sources','manage lead statuses','manage lost reasons','manage sales teams','manage campaigns','manage lead scoring','manage sales targets','view CRM dashboard','view CRM reports','export CRM reports','override communication consent','manage CRM settings'];
        foreach ($names as $name) DB::table('permissions')->updateOrInsert(['name' => $name], ['module' => 'crm', 'description' => ucfirst($name), 'created_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        foreach ([1, 2] as $roleId) foreach ($ids as $id) DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $id], ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // CRM records are retained intentionally for audit and relationship history.
    }
};
