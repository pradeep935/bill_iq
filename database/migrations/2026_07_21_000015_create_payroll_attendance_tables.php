<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->accountSettings();
        $this->createMasters();
        $this->createAttendanceLeave();
        $this->createPayroll();
        $this->addForeignKeys();
        $this->journalReferences();
        $this->seedPermissions();
    }

    public function down(): void
    {
        foreach ([
            'employee_settlements', 'payroll_statutory_liabilities', 'payroll_payments', 'payroll_payment_batches',
            'employee_loan_installments', 'employee_loans', 'employee_advances', 'payroll_adjustments',
            'employee_payroll_components', 'employee_payrolls', 'payroll_runs', 'payroll_periods',
            'overtime_requests', 'leave_requests', 'employee_leave_balances', 'leave_policy_rules', 'leave_policies',
            'leave_types', 'attendance_regularization_requests', 'attendance_punches', 'attendance_records',
            'attendance_policies', 'employee_shift_assignments', 'shifts', 'employee_salary_components',
            'employee_salary_assignments', 'salary_structure_components', 'salary_structures', 'salary_components',
            'employee_documents', 'employees', 'employee_grades', 'designations', 'departments', 'payroll_settings',
            'gratuity_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function accountSettings(): void
    {
        if (!Schema::hasTable('business_account_settings')) return;

        Schema::table('business_account_settings', function (Blueprint $table) {
            foreach ([
                'salary_expense_account_id', 'salary_payable_account_id', 'pf_payable_account_id',
                'esi_payable_account_id', 'professional_tax_payable_account_id', 'salary_tds_payable_account_id',
                'payroll_round_off_account_id', 'employee_advance_account_id', 'employee_loan_account_id',
                'reimbursement_payable_account_id', 'bonus_expense_account_id', 'gratuity_expense_account_id',
            ] as $column) {
                if (!Schema::hasColumn('business_account_settings', $column)) {
                    $table->unsignedBigInteger($column)->nullable()->index();
                }
            }
        });
    }

    private function createMasters(): void
    {
        if (!Schema::hasTable('payroll_settings')) {
            Schema::create('payroll_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id')->index();
                $table->string('payroll_frequency', 30)->default('monthly');
                $table->unsignedTinyInteger('payroll_period_start_day')->default(1);
                $table->unsignedTinyInteger('payroll_period_end_day')->default(31);
                $table->string('default_payment_date_rule', 40)->default('period_end');
                $table->string('salary_calculation_basis', 40)->default('calendar_days');
                $table->boolean('attendance_integration_enabled')->default(true);
                $table->boolean('leave_integration_enabled')->default(true);
                $table->boolean('overtime_enabled')->default(true);
                $table->boolean('negative_salary_allowed')->default(false);
                $table->boolean('payroll_approval_required')->default(true);
                $table->boolean('auto_post_payroll_accounting')->default(false);
                foreach ([
                    'default_salary_payable_account_id', 'default_employee_advance_account_id', 'default_employee_loan_account_id',
                    'default_reimbursement_payable_account_id', 'default_round_off_account_id',
                    'default_payroll_expense_account_id', 'default_payment_account_id',
                ] as $column) $table->unsignedBigInteger($column)->nullable()->index();
                $table->string('payslip_number_prefix', 20)->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->unique('business_id');
            });
        }

        if (!Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('department_code', 50); $table->string('department_name'); $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->unsignedBigInteger('department_head_id')->nullable()->index(); $table->unsignedBigInteger('cost_center_id')->nullable()->index();
                $table->string('status', 20)->default('active')->index(); $table->timestamps(); $table->softDeletes();
                $table->unique(['business_id', 'department_code']);
            });
        }

        if (!Schema::hasTable('employee_grades')) {
            Schema::create('employee_grades', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->string('grade_code', 50); $table->string('grade_name');
                $table->unsignedInteger('level_number')->nullable(); $table->decimal('minimum_salary', 15, 2)->nullable(); $table->decimal('maximum_salary', 15, 2)->nullable();
                $table->text('description')->nullable(); $table->string('status', 20)->default('active')->index(); $table->timestamps();
                $table->unique(['business_id', 'grade_code']);
            });
        }

        if (!Schema::hasTable('designations')) {
            Schema::create('designations', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->string('designation_code', 50); $table->string('designation_name');
                $table->unsignedBigInteger('grade_id')->nullable()->index(); $table->text('description')->nullable();
                $table->string('status', 20)->default('active')->index(); $table->timestamps(); $table->softDeletes();
                $table->unique(['business_id', 'designation_code']);
            });
        }

        if (!Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('branch_id')->index();
                $table->string('employee_code', 50); $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('title', 20)->nullable(); $table->string('first_name'); $table->string('middle_name')->nullable(); $table->string('last_name')->nullable(); $table->string('display_name');
                $table->string('gender', 30)->nullable(); $table->date('date_of_birth')->nullable(); $table->string('mobile', 30); $table->string('alternate_mobile', 30)->nullable();
                $table->string('email')->nullable(); $table->string('personal_email')->nullable(); $table->string('emergency_contact_name')->nullable(); $table->string('emergency_contact_number', 30)->nullable();
                $table->string('marital_status', 30)->nullable(); $table->string('blood_group', 10)->nullable(); $table->string('profile_photo_path')->nullable();
                $table->json('current_address_json')->nullable(); $table->json('permanent_address_json')->nullable();
                $table->unsignedBigInteger('department_id')->nullable()->index(); $table->unsignedBigInteger('designation_id')->nullable()->index(); $table->unsignedBigInteger('reporting_manager_id')->nullable()->index();
                $table->unsignedBigInteger('cost_center_id')->nullable()->index(); $table->string('employment_type', 40)->default('permanent')->index(); $table->string('employment_status', 40)->default('active')->index();
                $table->date('joining_date'); $table->date('confirmation_date')->nullable(); $table->date('probation_end_date')->nullable(); $table->date('resignation_date')->nullable(); $table->date('last_working_date')->nullable(); $table->date('retirement_date')->nullable();
                $table->unsignedInteger('notice_period_days')->nullable(); $table->unsignedBigInteger('work_location_id')->nullable()->index(); $table->unsignedBigInteger('shift_id')->nullable()->index();
                $table->unsignedBigInteger('weekly_off_policy_id')->nullable()->index(); $table->unsignedBigInteger('attendance_policy_id')->nullable()->index(); $table->unsignedBigInteger('leave_policy_id')->nullable()->index(); $table->unsignedBigInteger('salary_structure_id')->nullable()->index();
                $table->string('payroll_status', 30)->default('included')->index(); $table->string('bank_account_name')->nullable(); $table->string('bank_account_number')->nullable(); $table->string('bank_name')->nullable(); $table->string('bank_branch')->nullable(); $table->string('bank_ifsc', 20)->nullable();
                $table->string('payment_mode', 40)->default('bank_transfer'); $table->string('pan', 20)->nullable(); $table->string('aadhaar_masked', 20)->nullable(); $table->string('uan', 30)->nullable(); $table->string('pf_number', 30)->nullable(); $table->string('esi_number', 30)->nullable();
                $table->unsignedBigInteger('professional_tax_state_id')->nullable()->index(); $table->string('tax_regime', 30)->nullable();
                $table->string('status', 20)->default('active')->index(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->unsignedBigInteger('updated_by')->nullable()->index(); $table->timestamps(); $table->softDeletes();
                $table->unique(['business_id', 'employee_code']);
            });
        }

        if (!Schema::hasTable('employee_documents')) {
            Schema::create('employee_documents', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index();
                $table->string('document_type', 80); $table->string('document_number')->nullable(); $table->date('issue_date')->nullable(); $table->date('expiry_date')->nullable()->index();
                $table->string('file_path'); $table->string('original_name'); $table->string('mime_type', 120); $table->unsignedBigInteger('file_size')->default(0);
                $table->string('verification_status', 30)->default('pending'); $table->unsignedBigInteger('verified_by')->nullable()->index(); $table->timestamp('verified_at')->nullable();
                $table->text('remarks')->nullable(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->timestamps(); $table->softDeletes();
            });
        }

        if (!Schema::hasTable('salary_components')) {
            Schema::create('salary_components', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->string('component_code', 50); $table->string('component_name');
                $table->string('component_type', 40)->index(); $table->string('calculation_type', 40)->default('fixed'); $table->text('calculation_formula')->nullable();
                $table->unsignedBigInteger('percentage_base_component_id')->nullable()->index(); $table->decimal('default_value', 15, 2)->nullable(); $table->decimal('minimum_value', 15, 2)->nullable(); $table->decimal('maximum_value', 15, 2)->nullable();
                $table->boolean('taxable')->default(true); $table->boolean('statutory')->default(false); $table->boolean('recurring')->default(true); $table->boolean('attendance_dependent')->default(false);
                $table->boolean('prorate_on_joining')->default(true); $table->boolean('prorate_on_exit')->default(true); $table->boolean('include_in_gross')->default(true); $table->boolean('include_in_ctc')->default(true); $table->boolean('include_in_net_pay')->default(true); $table->boolean('employer_contribution')->default(false);
                $table->unsignedBigInteger('expense_account_id')->nullable()->index(); $table->unsignedBigInteger('payable_account_id')->nullable()->index(); $table->unsignedBigInteger('deduction_account_id')->nullable()->index();
                $table->unsignedInteger('display_order')->default(0); $table->string('status', 20)->default('active')->index(); $table->boolean('is_system')->default(false); $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps(); $table->softDeletes(); $table->unique(['business_id', 'component_code']);
            });
        }

        if (!Schema::hasTable('salary_structures')) {
            Schema::create('salary_structures', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->string('structure_code', 50); $table->string('structure_name');
                $table->unsignedBigInteger('grade_id')->nullable()->index(); $table->string('employment_type', 40)->nullable()->index();
                $table->date('effective_from'); $table->date('effective_to')->nullable(); $table->decimal('annual_ctc', 15, 2)->nullable(); $table->decimal('monthly_gross', 15, 2)->nullable();
                $table->text('description')->nullable(); $table->string('status', 30)->default('draft')->index(); $table->unsignedInteger('version_number')->default(1);
                $table->unsignedBigInteger('created_by')->nullable()->index(); $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->timestamp('approved_at')->nullable();
                $table->timestamps(); $table->softDeletes(); $table->unique(['business_id', 'structure_code', 'version_number']);
            });
        }

        if (!Schema::hasTable('salary_structure_components')) {
            Schema::create('salary_structure_components', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('salary_structure_id')->index(); $table->unsignedBigInteger('salary_component_id')->index();
                $table->string('calculation_type', 40)->default('fixed'); $table->decimal('fixed_amount', 15, 2)->nullable(); $table->decimal('percentage', 8, 4)->nullable();
                $table->unsignedBigInteger('base_component_id')->nullable()->index(); $table->text('formula')->nullable(); $table->decimal('annual_amount', 15, 2)->nullable(); $table->decimal('monthly_amount', 15, 2)->nullable();
                $table->unsignedInteger('display_order')->default(0); $table->timestamps();
            });
        }

        if (!Schema::hasTable('employee_salary_assignments')) {
            Schema::create('employee_salary_assignments', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->unsignedBigInteger('salary_structure_id')->index();
                $table->date('effective_from'); $table->date('effective_to')->nullable(); $table->decimal('annual_ctc', 15, 2); $table->decimal('monthly_gross', 15, 2); $table->decimal('monthly_net_estimate', 15, 2)->nullable();
                $table->text('reason')->nullable(); $table->string('status', 30)->default('active')->index(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->timestamp('approved_at')->nullable();
                $table->timestamps(); $table->index(['employee_id', 'effective_from', 'effective_to']);
            });
        }

        if (!Schema::hasTable('employee_salary_components')) {
            Schema::create('employee_salary_components', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('employee_salary_assignment_id')->index(); $table->unsignedBigInteger('salary_component_id')->index();
                $table->string('calculation_type', 40)->default('fixed'); $table->decimal('fixed_amount', 15, 2)->nullable(); $table->decimal('percentage', 8, 4)->nullable(); $table->text('formula')->nullable();
                $table->decimal('monthly_amount', 15, 2); $table->decimal('annual_amount', 15, 2); $table->boolean('overridden')->default(false); $table->text('override_reason')->nullable(); $table->timestamps();
            });
        }

        if (!Schema::hasTable('gratuity_settings')) {
            Schema::create('gratuity_settings', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->date('effective_from'); $table->date('effective_to')->nullable();
                $table->unsignedInteger('eligibility_months')->default(60); $table->string('salary_base', 60)->default('basic'); $table->text('formula')->nullable(); $table->decimal('maximum_limit', 15, 2)->nullable();
                $table->string('status', 20)->default('active')->index(); $table->timestamps();
            });
        }
    }

    private function createAttendanceLeave(): void
    {
        if (!Schema::hasTable('shifts')) {
            Schema::create('shifts', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('branch_id')->nullable()->index(); $table->string('shift_code', 50); $table->string('shift_name');
                $table->time('start_time'); $table->time('end_time'); $table->unsignedInteger('break_minutes')->default(0); $table->unsignedInteger('grace_in_minutes')->default(0); $table->unsignedInteger('grace_out_minutes')->default(0);
                $table->unsignedInteger('half_day_after_minutes')->nullable(); $table->unsignedInteger('absent_after_minutes')->nullable(); $table->unsignedInteger('minimum_work_minutes')->nullable(); $table->unsignedInteger('overtime_after_minutes')->nullable();
                $table->boolean('night_shift')->default(false); $table->boolean('crosses_midnight')->default(false); $table->string('status', 20)->default('active')->index(); $table->timestamps();
                $table->unique(['business_id', 'shift_code']);
            });
        }
        if (!Schema::hasTable('employee_shift_assignments')) {
            Schema::create('employee_shift_assignments', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->unsignedBigInteger('shift_id')->index();
                $table->date('effective_from'); $table->date('effective_to')->nullable(); $table->string('status', 20)->default('active'); $table->unsignedBigInteger('assigned_by')->nullable()->index(); $table->timestamps();
                $table->index(['employee_id', 'effective_from', 'effective_to']);
            });
        }
        if (!Schema::hasTable('attendance_policies')) {
            Schema::create('attendance_policies', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->string('policy_name'); $table->string('attendance_source', 30)->default('manual');
                $table->boolean('late_mark_enabled')->default(true); $table->boolean('early_exit_enabled')->default(true); $table->boolean('half_day_enabled')->default(true); $table->boolean('overtime_enabled')->default(true); $table->boolean('auto_absent_enabled')->default(false);
                $table->string('missing_punch_rule', 40)->default('require_regularization'); $table->boolean('attendance_regularization_enabled')->default(true); $table->boolean('regularization_approval_required')->default(true);
                $table->unsignedInteger('monthly_late_mark_limit')->nullable(); $table->string('late_mark_conversion_rule')->nullable(); $table->string('status', 20)->default('active')->index(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('attendance_records')) {
            Schema::create('attendance_records', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('branch_id')->index(); $table->unsignedBigInteger('employee_id')->index();
                $table->date('attendance_date')->index(); $table->unsignedBigInteger('shift_id')->nullable()->index(); $table->dateTime('first_in_at')->nullable(); $table->dateTime('last_out_at')->nullable();
                $table->unsignedInteger('total_work_minutes')->default(0); $table->unsignedInteger('break_minutes')->default(0); $table->unsignedInteger('payable_minutes')->default(0); $table->unsignedInteger('overtime_minutes')->default(0); $table->unsignedInteger('late_minutes')->default(0); $table->unsignedInteger('early_exit_minutes')->default(0);
                $table->string('attendance_status', 40)->default('present')->index(); $table->string('source', 30)->default('manual'); $table->string('source_reference')->nullable(); $table->text('remarks')->nullable();
                $table->boolean('locked')->default(false); $table->unsignedBigInteger('processed_in_payroll_id')->nullable()->index(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->unsignedBigInteger('updated_by')->nullable()->index(); $table->timestamps();
                $table->unique(['employee_id', 'attendance_date']); $table->index(['business_id', 'attendance_date']);
            });
        }
        if (!Schema::hasTable('attendance_punches')) {
            Schema::create('attendance_punches', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->dateTime('punch_at')->index();
                $table->string('punch_type', 30)->default('unknown'); $table->string('source', 30)->default('manual'); $table->string('device_id')->nullable(); $table->decimal('latitude', 10, 7)->nullable(); $table->decimal('longitude', 10, 7)->nullable();
                $table->string('location_name')->nullable(); $table->string('image_path')->nullable(); $table->json('raw_payload_json')->nullable(); $table->unsignedBigInteger('attendance_record_id')->nullable()->index(); $table->timestamp('created_at')->nullable();
                $table->unique(['employee_id', 'punch_at', 'punch_type']);
            });
        }
        if (!Schema::hasTable('attendance_regularization_requests')) {
            Schema::create('attendance_regularization_requests', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->date('attendance_date')->index(); $table->string('request_type', 40);
                $table->dateTime('requested_in_at')->nullable(); $table->dateTime('requested_out_at')->nullable(); $table->string('requested_status', 40)->nullable(); $table->text('reason'); $table->string('attachment_path')->nullable();
                $table->string('status', 30)->default('draft')->index(); $table->timestamp('requested_at')->nullable(); $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->timestamp('approved_at')->nullable(); $table->unsignedBigInteger('rejected_by')->nullable()->index(); $table->timestamp('rejected_at')->nullable(); $table->text('rejection_reason')->nullable(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('leave_types')) {
            Schema::create('leave_types', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->string('leave_code', 50); $table->string('leave_name'); $table->string('leave_category', 40);
                $table->boolean('paid')->default(true); $table->boolean('carry_forward_allowed')->default(false); $table->decimal('maximum_carry_forward', 8, 2)->nullable(); $table->boolean('encashment_allowed')->default(false); $table->decimal('maximum_encashment', 8, 2)->nullable();
                $table->boolean('negative_balance_allowed')->default(false); $table->boolean('half_day_allowed')->default(true); $table->decimal('attachment_required_after_days', 8, 2)->nullable(); $table->unsignedInteger('minimum_notice_days')->nullable(); $table->unsignedInteger('maximum_continuous_days')->nullable();
                $table->boolean('probation_eligible')->default(false); $table->boolean('sandwich_rule_enabled')->default(false); $table->string('status', 20)->default('active')->index(); $table->timestamps(); $table->unique(['business_id', 'leave_code']);
            });
        }
        if (!Schema::hasTable('leave_policies')) {
            Schema::create('leave_policies', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->string('policy_code', 50); $table->string('policy_name'); $table->date('effective_from'); $table->date('effective_to')->nullable();
                $table->string('employment_type', 40)->nullable()->index(); $table->unsignedBigInteger('grade_id')->nullable()->index(); $table->unsignedBigInteger('branch_id')->nullable()->index(); $table->string('status', 20)->default('active')->index(); $table->timestamps();
                $table->unique(['business_id', 'policy_code']);
            });
        }
        if (!Schema::hasTable('leave_policy_rules')) {
            Schema::create('leave_policy_rules', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('leave_policy_id')->index(); $table->unsignedBigInteger('leave_type_id')->index(); $table->decimal('annual_entitlement', 8, 2)->default(0); $table->string('accrual_frequency', 30)->default('monthly');
                $table->decimal('accrual_amount', 8, 2)->nullable(); $table->decimal('carry_forward_limit', 8, 2)->nullable(); $table->decimal('encashment_limit', 8, 2)->nullable(); $table->unsignedInteger('minimum_service_days')->nullable(); $table->string('lapse_rule')->nullable(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('employee_leave_balances')) {
            Schema::create('employee_leave_balances', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->unsignedBigInteger('leave_type_id')->index(); $table->string('financial_year', 20)->index();
                foreach (['opening_balance', 'accrued', 'used', 'adjusted', 'encashed', 'lapsed', 'closing_balance'] as $column) $table->decimal($column, 8, 2)->default(0);
                $table->timestamps(); $table->unique(['employee_id', 'leave_type_id', 'financial_year']);
            });
        }
        if (!Schema::hasTable('leave_requests')) {
            Schema::create('leave_requests', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->unsignedBigInteger('leave_type_id')->index();
                $table->date('from_date')->index(); $table->date('to_date')->index(); $table->string('from_session', 20)->nullable(); $table->string('to_session', 20)->nullable(); $table->decimal('total_days', 8, 2);
                $table->text('reason')->nullable(); $table->string('contact_during_leave')->nullable(); $table->string('attachment_path')->nullable(); $table->text('handover_notes')->nullable();
                $table->string('status', 30)->default('draft')->index(); $table->timestamp('applied_at')->nullable(); $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->timestamp('approved_at')->nullable(); $table->unsignedBigInteger('rejected_by')->nullable()->index(); $table->timestamp('rejected_at')->nullable(); $table->text('rejection_reason')->nullable(); $table->unsignedBigInteger('cancelled_by')->nullable()->index(); $table->timestamp('cancelled_at')->nullable(); $table->timestamps();
                $table->index(['employee_id', 'from_date', 'to_date']);
            });
        }
        if (!Schema::hasTable('overtime_requests')) {
            Schema::create('overtime_requests', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->date('overtime_date')->index(); $table->unsignedInteger('overtime_minutes')->default(0);
                $table->decimal('rate_multiplier', 8, 4)->default(1); $table->decimal('amount', 15, 2)->default(0); $table->text('reason')->nullable(); $table->string('status', 30)->default('draft')->index();
                $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->timestamp('approved_at')->nullable(); $table->unsignedBigInteger('processed_in_payroll_id')->nullable()->index(); $table->timestamps();
            });
        }
    }

    private function createPayroll(): void
    {
        if (!Schema::hasTable('payroll_periods')) {
            Schema::create('payroll_periods', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->string('period_code', 50); $table->date('period_start'); $table->date('period_end'); $table->date('payment_date')->nullable();
                $table->string('period_type', 30)->default('regular'); $table->string('status', 30)->default('open')->index(); $table->timestamps(); $table->unique(['business_id', 'period_code']); $table->index(['business_id', 'period_start', 'period_end']);
            });
        }
        if (!Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('branch_id')->nullable()->index(); $table->unsignedBigInteger('payroll_period_id')->nullable()->index();
                $table->string('run_number', 50); $table->string('run_type', 30)->default('regular'); $table->date('period_start'); $table->date('period_end'); $table->date('payment_date')->nullable(); $table->string('financial_year', 20)->nullable();
                $table->unsignedInteger('employee_count')->default(0); $table->decimal('gross_earnings', 15, 2)->default(0); $table->decimal('total_deductions', 15, 2)->default(0); $table->decimal('employer_contributions', 15, 2)->default(0); $table->decimal('reimbursements', 15, 2)->default(0); $table->decimal('net_pay', 15, 2)->default(0);
                $table->string('status', 30)->default('draft')->index(); $table->unsignedBigInteger('journal_voucher_id')->nullable()->index(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->timestamp('approved_at')->nullable(); $table->timestamps();
                $table->unique(['business_id', 'run_number']); $table->index(['business_id', 'period_start', 'period_end', 'status']);
            });
        }
        if (!Schema::hasTable('employee_payrolls')) {
            Schema::create('employee_payrolls', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('payroll_run_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->unsignedBigInteger('salary_assignment_id')->nullable()->index();
                $table->decimal('calendar_days', 8, 2)->default(0); $table->decimal('paid_days', 8, 2)->default(0); $table->decimal('unpaid_days', 8, 2)->default(0); $table->decimal('overtime_hours', 8, 2)->default(0);
                $table->decimal('gross_earnings', 15, 2)->default(0); $table->decimal('total_deductions', 15, 2)->default(0); $table->decimal('employer_contributions', 15, 2)->default(0); $table->decimal('reimbursements', 15, 2)->default(0); $table->decimal('net_pay', 15, 2)->default(0);
                $table->string('status', 30)->default('calculated')->index(); $table->json('exceptions_json')->nullable(); $table->string('payslip_number', 80)->nullable()->index(); $table->timestamp('payslip_generated_at')->nullable(); $table->timestamps();
                $table->unique(['payroll_run_id', 'employee_id']);
            });
        }
        if (!Schema::hasTable('employee_payroll_components')) {
            Schema::create('employee_payroll_components', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_payroll_id')->index(); $table->unsignedBigInteger('salary_component_id')->nullable()->index();
                $table->string('component_code', 50); $table->string('component_name'); $table->string('component_type', 40)->index(); $table->decimal('amount', 15, 2)->default(0);
                $table->boolean('statutory')->default(false); $table->boolean('employer_contribution')->default(false); $table->json('snapshot_json')->nullable(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('payroll_adjustments')) {
            Schema::create('payroll_adjustments', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->unsignedBigInteger('payroll_run_id')->nullable()->index(); $table->string('adjustment_type', 40);
                $table->decimal('amount', 15, 2); $table->text('reason'); $table->string('status', 30)->default('draft')->index(); $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->timestamp('approved_at')->nullable(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('employee_advances')) {
            Schema::create('employee_advances', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->date('advance_date'); $table->decimal('amount', 15, 2);
                $table->decimal('recovered_amount', 15, 2)->default(0); $table->decimal('outstanding_amount', 15, 2)->default(0); $table->unsignedBigInteger('payment_account_id')->nullable()->index(); $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->text('reason')->nullable(); $table->string('status', 30)->default('draft')->index(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('employee_loans')) {
            Schema::create('employee_loans', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->string('loan_number', 50); $table->date('loan_date'); $table->decimal('principal_amount', 15, 2);
                $table->decimal('interest_rate', 8, 4)->default(0); $table->string('interest_method', 40)->default('flat'); $table->unsignedInteger('tenure_months')->default(1); $table->decimal('emi_amount', 15, 2)->default(0);
                $table->decimal('recovered_principal', 15, 2)->default(0); $table->decimal('recovered_interest', 15, 2)->default(0); $table->decimal('outstanding_amount', 15, 2)->default(0); $table->unsignedBigInteger('payment_account_id')->nullable()->index(); $table->unsignedBigInteger('journal_voucher_id')->nullable()->index();
                $table->text('reason')->nullable(); $table->string('status', 30)->default('draft')->index(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->timestamps(); $table->unique(['business_id', 'loan_number']);
            });
        }
        if (!Schema::hasTable('employee_loan_installments')) {
            Schema::create('employee_loan_installments', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_loan_id')->index(); $table->date('due_date')->index(); $table->decimal('principal_amount', 15, 2); $table->decimal('interest_amount', 15, 2)->default(0);
                $table->decimal('paid_amount', 15, 2)->default(0); $table->string('status', 30)->default('pending')->index(); $table->unsignedBigInteger('employee_payroll_id')->nullable()->index(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('payroll_payment_batches')) {
            Schema::create('payroll_payment_batches', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('branch_id')->nullable()->index(); $table->unsignedBigInteger('payroll_run_id')->index(); $table->string('batch_number', 50);
                $table->date('payment_date'); $table->string('payment_mode', 40)->default('bank_transfer'); $table->unsignedBigInteger('bank_account_id')->nullable()->index(); $table->unsignedInteger('employee_count')->default(0); $table->decimal('total_amount', 15, 2)->default(0);
                $table->string('status', 30)->default('draft')->index(); $table->string('payment_file_path')->nullable(); $table->unsignedBigInteger('journal_voucher_id')->nullable()->index(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->unsignedBigInteger('processed_by')->nullable()->index(); $table->timestamp('processed_at')->nullable(); $table->timestamps(); $table->unique(['business_id', 'batch_number']);
            });
        }
        if (!Schema::hasTable('payroll_payments')) {
            Schema::create('payroll_payments', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('payroll_payment_batch_id')->index(); $table->unsignedBigInteger('employee_payroll_id')->index(); $table->unsignedBigInteger('employee_id')->index();
                $table->decimal('payable_amount', 15, 2); $table->decimal('paid_amount', 15, 2)->default(0); $table->string('payment_reference')->nullable(); $table->string('status', 30)->default('pending')->index(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('payroll_statutory_liabilities')) {
            Schema::create('payroll_statutory_liabilities', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('payroll_run_id')->nullable()->index(); $table->string('liability_type', 40)->index(); $table->date('period_start'); $table->date('period_end');
                $table->decimal('employee_contribution', 15, 2)->default(0); $table->decimal('employer_contribution', 15, 2)->default(0); $table->decimal('total_payable', 15, 2)->default(0); $table->decimal('paid_amount', 15, 2)->default(0); $table->decimal('outstanding_amount', 15, 2)->default(0);
                $table->date('due_date')->nullable(); $table->date('payment_date')->nullable(); $table->string('challan_number')->nullable(); $table->string('payment_reference')->nullable(); $table->string('status', 30)->default('pending')->index(); $table->timestamps();
            });
        }
        if (!Schema::hasTable('employee_settlements')) {
            Schema::create('employee_settlements', function (Blueprint $table) {
                $table->id(); $table->unsignedBigInteger('business_id')->index(); $table->unsignedBigInteger('employee_id')->index(); $table->string('settlement_number', 50); $table->date('resignation_date')->nullable(); $table->date('last_working_date'); $table->date('settlement_date'); $table->decimal('payable_days', 8, 2)->default(0);
                foreach (['salary_payable', 'leave_encashment', 'bonus_payable', 'incentive_payable', 'gratuity_payable', 'reimbursement_payable', 'notice_pay_recovery', 'loan_recovery', 'advance_recovery', 'asset_recovery', 'other_earnings', 'other_deductions', 'tax_deduction', 'net_settlement'] as $column) $table->decimal($column, 15, 2)->default(0);
                $table->string('status', 30)->default('draft')->index(); $table->unsignedBigInteger('payroll_run_id')->nullable()->index(); $table->unsignedBigInteger('journal_voucher_id')->nullable()->index(); $table->unsignedBigInteger('payment_batch_id')->nullable()->index(); $table->unsignedBigInteger('created_by')->nullable()->index(); $table->unsignedBigInteger('reviewed_by')->nullable()->index(); $table->unsignedBigInteger('approved_by')->nullable()->index(); $table->unsignedBigInteger('paid_by')->nullable()->index(); $table->timestamps(); $table->unique(['business_id', 'settlement_number']);
            });
        }
    }

    private function journalReferences(): void
    {
        if (!Schema::hasTable('journal_entries')) return;
        Schema::table('journal_entries', function (Blueprint $table) {
            foreach (['employee_id', 'payroll_run_id', 'employee_payroll_id'] as $column) {
                if (!Schema::hasColumn('journal_entries', $column)) $table->unsignedBigInteger($column)->nullable()->index();
            }
        });
    }

    private function addForeignKeys(): void
    {
        $foreignKeys = [
            ['payroll_settings', 'business_id', 'companies', 'cascade'],
            ['departments', 'business_id', 'companies', 'cascade'], ['departments', 'branch_id', 'branches', 'set null'], ['departments', 'parent_id', 'departments', 'set null'], ['departments', 'department_head_id', 'employees', 'set null'],
            ['employee_grades', 'business_id', 'companies', 'cascade'],
            ['designations', 'business_id', 'companies', 'cascade'], ['designations', 'grade_id', 'employee_grades', 'set null'],
            ['employees', 'business_id', 'companies', 'cascade'], ['employees', 'branch_id', 'branches', 'restrict'], ['employees', 'user_id', 'users', 'set null'], ['employees', 'department_id', 'departments', 'set null'], ['employees', 'designation_id', 'designations', 'set null'], ['employees', 'reporting_manager_id', 'employees', 'set null'], ['employees', 'salary_structure_id', 'salary_structures', 'set null'],
            ['employee_documents', 'business_id', 'companies', 'cascade'], ['employee_documents', 'employee_id', 'employees', 'cascade'],
            ['salary_components', 'business_id', 'companies', 'cascade'], ['salary_components', 'percentage_base_component_id', 'salary_components', 'set null'],
            ['salary_structures', 'business_id', 'companies', 'cascade'], ['salary_structures', 'grade_id', 'employee_grades', 'set null'],
            ['salary_structure_components', 'salary_structure_id', 'salary_structures', 'cascade'], ['salary_structure_components', 'salary_component_id', 'salary_components', 'restrict'],
            ['employee_salary_assignments', 'business_id', 'companies', 'cascade'], ['employee_salary_assignments', 'employee_id', 'employees', 'cascade'], ['employee_salary_assignments', 'salary_structure_id', 'salary_structures', 'restrict'],
            ['employee_salary_components', 'employee_salary_assignment_id', 'employee_salary_assignments', 'cascade'], ['employee_salary_components', 'salary_component_id', 'salary_components', 'restrict'],
            ['shifts', 'business_id', 'companies', 'cascade'], ['shifts', 'branch_id', 'branches', 'set null'],
            ['employee_shift_assignments', 'business_id', 'companies', 'cascade'], ['employee_shift_assignments', 'employee_id', 'employees', 'cascade'], ['employee_shift_assignments', 'shift_id', 'shifts', 'restrict'],
            ['attendance_policies', 'business_id', 'companies', 'cascade'],
            ['attendance_records', 'business_id', 'companies', 'cascade'], ['attendance_records', 'branch_id', 'branches', 'restrict'], ['attendance_records', 'employee_id', 'employees', 'cascade'], ['attendance_records', 'shift_id', 'shifts', 'set null'],
            ['attendance_punches', 'business_id', 'companies', 'cascade'], ['attendance_punches', 'employee_id', 'employees', 'cascade'], ['attendance_punches', 'attendance_record_id', 'attendance_records', 'set null'],
            ['attendance_regularization_requests', 'business_id', 'companies', 'cascade'], ['attendance_regularization_requests', 'employee_id', 'employees', 'cascade'],
            ['leave_types', 'business_id', 'companies', 'cascade'],
            ['leave_policies', 'business_id', 'companies', 'cascade'], ['leave_policies', 'grade_id', 'employee_grades', 'set null'], ['leave_policies', 'branch_id', 'branches', 'set null'],
            ['leave_policy_rules', 'leave_policy_id', 'leave_policies', 'cascade'], ['leave_policy_rules', 'leave_type_id', 'leave_types', 'restrict'],
            ['employee_leave_balances', 'business_id', 'companies', 'cascade'], ['employee_leave_balances', 'employee_id', 'employees', 'cascade'], ['employee_leave_balances', 'leave_type_id', 'leave_types', 'restrict'],
            ['leave_requests', 'business_id', 'companies', 'cascade'], ['leave_requests', 'employee_id', 'employees', 'cascade'], ['leave_requests', 'leave_type_id', 'leave_types', 'restrict'],
            ['overtime_requests', 'business_id', 'companies', 'cascade'], ['overtime_requests', 'employee_id', 'employees', 'cascade'],
            ['payroll_periods', 'business_id', 'companies', 'cascade'],
            ['payroll_runs', 'business_id', 'companies', 'cascade'], ['payroll_runs', 'branch_id', 'branches', 'set null'], ['payroll_runs', 'payroll_period_id', 'payroll_periods', 'set null'],
            ['employee_payrolls', 'business_id', 'companies', 'cascade'], ['employee_payrolls', 'payroll_run_id', 'payroll_runs', 'cascade'], ['employee_payrolls', 'employee_id', 'employees', 'cascade'], ['employee_payrolls', 'salary_assignment_id', 'employee_salary_assignments', 'set null'],
            ['employee_payroll_components', 'business_id', 'companies', 'cascade'], ['employee_payroll_components', 'employee_payroll_id', 'employee_payrolls', 'cascade'], ['employee_payroll_components', 'salary_component_id', 'salary_components', 'set null'],
            ['payroll_adjustments', 'business_id', 'companies', 'cascade'], ['payroll_adjustments', 'employee_id', 'employees', 'cascade'], ['payroll_adjustments', 'payroll_run_id', 'payroll_runs', 'set null'],
            ['employee_advances', 'business_id', 'companies', 'cascade'], ['employee_advances', 'employee_id', 'employees', 'cascade'],
            ['employee_loans', 'business_id', 'companies', 'cascade'], ['employee_loans', 'employee_id', 'employees', 'cascade'],
            ['employee_loan_installments', 'business_id', 'companies', 'cascade'], ['employee_loan_installments', 'employee_loan_id', 'employee_loans', 'cascade'],
            ['payroll_payment_batches', 'business_id', 'companies', 'cascade'], ['payroll_payment_batches', 'branch_id', 'branches', 'set null'], ['payroll_payment_batches', 'payroll_run_id', 'payroll_runs', 'cascade'],
            ['payroll_payments', 'business_id', 'companies', 'cascade'], ['payroll_payments', 'payroll_payment_batch_id', 'payroll_payment_batches', 'cascade'], ['payroll_payments', 'employee_payroll_id', 'employee_payrolls', 'cascade'], ['payroll_payments', 'employee_id', 'employees', 'cascade'],
            ['payroll_statutory_liabilities', 'business_id', 'companies', 'cascade'], ['payroll_statutory_liabilities', 'payroll_run_id', 'payroll_runs', 'set null'],
            ['employee_settlements', 'business_id', 'companies', 'cascade'], ['employee_settlements', 'employee_id', 'employees', 'cascade'], ['employee_settlements', 'payroll_run_id', 'payroll_runs', 'set null'],
            ['gratuity_settings', 'business_id', 'companies', 'cascade'],
        ];

        foreach ($foreignKeys as $definition) {
            [$tableName, $column, $references, $onDelete] = $definition;
            if (!Schema::hasTable($tableName) || !Schema::hasTable($references) || !Schema::hasColumn($tableName, $column)) continue;
            try {
                Schema::table($tableName, function (Blueprint $table) use ($column, $references, $onDelete) {
                    $table->foreign($column)->references('id')->on($references)->onDelete($onDelete);
                });
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    private function seedPermissions(): void
    {
        if (!Schema::hasTable('permissions')) return;
        $permissions = [
            'view employees', 'create employee', 'edit employee', 'deactivate employee', 'view employee personal data',
            'view salary details', 'manage salary components', 'manage salary structures', 'assign employee salary',
            'view attendance', 'manage attendance', 'manage shifts', 'manage leave types', 'manage leave policies',
            'approve leave', 'process payroll', 'approve payroll', 'post payroll', 'generate payslips',
            'create payroll payment batch', 'process payroll payment', 'manage statutory settings',
            'manage employee advances', 'manage employee loans', 'process full and final settlement',
            'view payroll dashboard', 'view payroll reports', 'export payroll reports', 'manage payroll settings',
        ];
        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission],
                ['module' => 'payroll', 'description' => ucwords($permission), 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
};
