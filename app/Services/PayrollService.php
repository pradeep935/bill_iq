<?php

namespace App\Services;

use App\Http\Controllers\AppController;
use App\Models\Account;
use App\Models\AttendanceRecord;
use App\Models\BusinessAccountSetting;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeGrade;
use App\Models\EmployeeLoan;
use App\Models\EmployeeLoanInstallment;
use App\Models\EmployeePayroll;
use App\Models\EmployeeSalaryAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPaymentBatch;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    private PayrollCalculationService $calculator;
    private AccountingPostingService $posting;

    public function __construct(PayrollCalculationService $calculator, AccountingPostingService $posting)
    {
        $this->calculator = $calculator;
        $this->posting = $posting;
    }

    public function references(): array
    {
        $businessId = AppController::businessId();
        return [
            'settings' => $this->settings(),
            'accounts' => Account::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('account_name')->get(['id', 'account_code', 'account_name', 'account_type']),
            'branches' => DB::table('branches')->where('business_id', $businessId)->orderBy('name')->get(['id', 'name', 'code']),
            'departments' => Department::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('department_name')->get(),
            'designations' => Designation::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('designation_name')->get(),
            'grades' => EmployeeGrade::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('level_number')->get(),
            'salary_components' => SalaryComponent::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('display_order')->get(),
            'salary_structures' => SalaryStructure::query()->where('business_id', $businessId)->with('components.component')->orderBy('structure_name')->get(),
            'leave_types' => LeaveType::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('leave_name')->get(),
            'employees' => Employee::query()->where('business_id', $businessId)->where('status', 'active')->orderBy('display_name')->limit(500)->get(['id', 'employee_code', 'display_name', 'branch_id', 'department_id', 'designation_id', 'employment_status', 'payroll_status']),
        ];
    }

    public function settings(): PayrollSetting
    {
        return PayrollSetting::query()->firstOrCreate(['business_id' => AppController::businessId()], [
            'payroll_frequency' => 'monthly', 'salary_calculation_basis' => 'calendar_days', 'status' => 'active',
        ]);
    }

    public function saveSettings(array $data): PayrollSetting
    {
        $allowed = ['payroll_frequency', 'payroll_period_start_day', 'payroll_period_end_day', 'default_payment_date_rule', 'salary_calculation_basis', 'attendance_integration_enabled', 'leave_integration_enabled', 'overtime_enabled', 'negative_salary_allowed', 'payroll_approval_required', 'auto_post_payroll_accounting', 'default_salary_payable_account_id', 'default_employee_advance_account_id', 'default_employee_loan_account_id', 'default_reimbursement_payable_account_id', 'default_round_off_account_id', 'default_payroll_expense_account_id', 'default_payment_account_id', 'payslip_number_prefix', 'status'];
        return PayrollSetting::query()->updateOrCreate(['business_id' => AppController::businessId()], array_intersect_key($data, array_flip($allowed)));
    }

    public function dashboard(): array
    {
        $businessId = AppController::businessId();
        $latest = PayrollRun::query()->where('business_id', $businessId)->latest('id')->first();
        return [
            'active_employees' => Employee::query()->where('business_id', $businessId)->where('employment_status', 'active')->count(),
            'payroll_employees' => Employee::query()->where('business_id', $businessId)->where('payroll_status', 'included')->count(),
            'gross_payroll' => (float) ($latest->gross_earnings ?? 0),
            'net_payroll' => (float) ($latest->net_pay ?? 0),
            'total_deductions' => (float) ($latest->total_deductions ?? 0),
            'employer_contributions' => (float) ($latest->employer_contributions ?? 0),
            'pending_attendance' => AttendanceRecord::query()->where('business_id', $businessId)->where('attendance_status', 'missing_punch')->count(),
            'pending_leave' => LeaveRequest::query()->where('business_id', $businessId)->where('status', 'submitted')->count(),
            'payroll_exceptions' => EmployeePayroll::query()->where('business_id', $businessId)->whereNotNull('exceptions_json')->count(),
            'salary_on_hold' => Employee::query()->where('business_id', $businessId)->where('payroll_status', 'on_hold')->count(),
            'loans_outstanding' => (float) EmployeeLoan::query()->where('business_id', $businessId)->sum('outstanding_amount'),
            'advances_outstanding' => (float) EmployeeAdvance::query()->where('business_id', $businessId)->sum('outstanding_amount'),
        ];
    }

    public function employees(array $filters = [])
    {
        return Employee::query()->with(['branch', 'department', 'designation', 'activeSalaryAssignment'])
            ->where('business_id', AppController::businessId())
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $i) => $i->where('employee_code', 'like', "%$s%")->orWhere('display_name', 'like', "%$s%")->orWhere('mobile', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, int $id) => $q->where('branch_id', $id))
            ->when($filters['department_id'] ?? null, fn (Builder $q, int $id) => $q->where('department_id', $id))
            ->when($filters['employment_status'] ?? null, fn (Builder $q, string $s) => $q->where('employment_status', $s))
            ->latest('id')->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    public function saveEmployee(array $data, ?int $id = null): Employee
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $employee = $id ? Employee::query()->where('business_id', $businessId)->findOrFail($id) : new Employee(['business_id' => $businessId, 'created_by' => Auth::id()]);
            $data['display_name'] = $data['display_name'] ?: trim($data['first_name'] . ' ' . ($data['middle_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
            $employee->fill(array_merge($data, ['updated_by' => Auth::id()]))->save();
            return $employee->fresh(['branch', 'department', 'designation']);
        });
    }

    public function saveMaster(string $type, array $data, ?int $id = null)
    {
        $businessId = AppController::businessId();
        $map = [
            'department' => [Department::class, ['department_code', 'department_name', 'branch_id', 'parent_id', 'department_head_id', 'cost_center_id', 'status']],
            'designation' => [Designation::class, ['designation_code', 'designation_name', 'grade_id', 'description', 'status']],
            'grade' => [EmployeeGrade::class, ['grade_code', 'grade_name', 'level_number', 'minimum_salary', 'maximum_salary', 'description', 'status']],
            'component' => [SalaryComponent::class, ['component_code', 'component_name', 'component_type', 'calculation_type', 'calculation_formula', 'percentage_base_component_id', 'default_value', 'minimum_value', 'maximum_value', 'taxable', 'statutory', 'recurring', 'attendance_dependent', 'prorate_on_joining', 'prorate_on_exit', 'include_in_gross', 'include_in_ctc', 'include_in_net_pay', 'employer_contribution', 'expense_account_id', 'payable_account_id', 'deduction_account_id', 'display_order', 'status', 'is_system']],
            'leave-type' => [LeaveType::class, ['leave_code', 'leave_name', 'leave_category', 'paid', 'carry_forward_allowed', 'maximum_carry_forward', 'encashment_allowed', 'maximum_encashment', 'negative_balance_allowed', 'half_day_allowed', 'status']],
        ];
        if (!isset($map[$type])) abort(404);
        [$class, $allowed] = $map[$type];
        $row = $id ? $class::query()->where('business_id', $businessId)->findOrFail($id) : new $class(['business_id' => $businessId, 'created_by' => Auth::id()]);
        $row->fill(array_intersect_key($data, array_flip($allowed)))->save();
        return $row;
    }

    public function saveAttendance(array $data, ?int $id = null): AttendanceRecord
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $employee = Employee::query()->where('business_id', $businessId)->findOrFail($data['employee_id']);
            $record = $id ? AttendanceRecord::query()->where('business_id', $businessId)->findOrFail($id) : AttendanceRecord::query()->firstOrNew(['employee_id' => $employee->id, 'attendance_date' => $data['attendance_date']]);
            if ($record->locked || $record->processed_in_payroll_id) throw ValidationException::withMessages(['attendance_date' => 'Payroll processed attendance cannot be edited directly.']);
            $minutes = $this->minutes($data['first_in_at'] ?? null, $data['last_out_at'] ?? null);
            $record->fill(array_merge($data, ['business_id' => $businessId, 'branch_id' => $employee->branch_id, 'total_work_minutes' => $minutes, 'payable_minutes' => $this->payableMinutes($data['attendance_status'], $minutes), 'updated_by' => Auth::id(), 'created_by' => $record->exists ? $record->created_by : Auth::id()]))->save();
            return $record->fresh(['employee', 'shift']);
        });
    }

    public function attendance(array $filters = [])
    {
        return AttendanceRecord::query()->with(['employee.department', 'shift'])->where('business_id', AppController::businessId())
            ->when($filters['from_date'] ?? null, fn (Builder $q, string $d) => $q->whereDate('attendance_date', '>=', $d))
            ->when($filters['to_date'] ?? null, fn (Builder $q, string $d) => $q->whereDate('attendance_date', '<=', $d))
            ->when($filters['employee_id'] ?? null, fn (Builder $q, int $id) => $q->where('employee_id', $id))
            ->latest('attendance_date')->paginate(50);
    }

    public function saveSalaryStructure(array $data, ?int $id = null): SalaryStructure
    {
        return DB::transaction(function () use ($data, $id) {
            $businessId = AppController::businessId();
            $structure = $id ? SalaryStructure::query()->where('business_id', $businessId)->findOrFail($id) : new SalaryStructure(['business_id' => $businessId, 'created_by' => Auth::id()]);
            if ($structure->exists && $structure->status === 'approved' && ($data['status'] ?? '') !== 'inactive') {
                $structure = new SalaryStructure(['business_id' => $businessId, 'created_by' => Auth::id(), 'version_number' => ((int) $structure->version_number) + 1]);
            }
            $payload = $data;
            unset($payload['components']);
            $structure->fill($payload)->save();
            $structure->components()->delete();
            foreach ($data['components'] as $index => $line) {
                $component = SalaryComponent::query()->where('business_id', $businessId)->findOrFail($line['salary_component_id']);
                $monthly = (float) ($line['monthly_amount'] ?? $line['fixed_amount'] ?? $component->default_value ?? 0);
                $structure->components()->create(array_merge($line, ['monthly_amount' => $monthly, 'annual_amount' => (float) ($line['annual_amount'] ?? $monthly * 12), 'display_order' => $index + 1]));
            }
            if ($structure->status === 'approved' && !$structure->approved_at) $structure->update(['approved_by' => Auth::id(), 'approved_at' => now()]);
            return $structure->fresh('components.component');
        });
    }

    public function assignSalary(array $data): EmployeeSalaryAssignment
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $employee = Employee::query()->where('business_id', $businessId)->findOrFail($data['employee_id']);
            $structure = SalaryStructure::query()->where('business_id', $businessId)->with('components.component')->findOrFail($data['salary_structure_id']);
            $overlap = EmployeeSalaryAssignment::query()->where('business_id', $businessId)->where('employee_id', $employee->id)->where('status', 'active')
                ->whereDate('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')->where(function ($q) use ($data) {
                    $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $data['effective_from']);
                })->exists();
            if ($overlap) throw ValidationException::withMessages(['effective_from' => 'Employee already has an active salary assignment in this date range.']);
            $assignment = EmployeeSalaryAssignment::query()->create([
                'business_id' => $businessId, 'employee_id' => $employee->id, 'salary_structure_id' => $structure->id,
                'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
                'annual_ctc' => $data['annual_ctc'] ?? (float) $structure->annual_ctc, 'monthly_gross' => $data['monthly_gross'] ?? (float) $structure->monthly_gross,
                'monthly_net_estimate' => $data['monthly_net_estimate'] ?? (float) $structure->monthly_gross, 'reason' => $data['reason'] ?? 'Salary assignment', 'status' => 'active',
                'created_by' => Auth::id(), 'approved_by' => Auth::id(), 'approved_at' => now(),
            ]);
            foreach ($structure->components as $line) {
                $assignment->components()->create([
                    'salary_component_id' => $line->salary_component_id, 'calculation_type' => $line->calculation_type, 'fixed_amount' => $line->fixed_amount, 'percentage' => $line->percentage,
                    'formula' => $line->formula, 'monthly_amount' => $line->monthly_amount ?: 0, 'annual_amount' => $line->annual_amount ?: 0,
                ]);
            }
            $employee->update(['salary_structure_id' => $structure->id]);
            return $assignment->fresh('components.component');
        });
    }

    public function payrollRuns(array $filters = [])
    {
        return PayrollRun::query()->with(['branch', 'employeePayrolls.employee.department'])->where('business_id', AppController::businessId())->latest('id')->paginate(20);
    }

    public function createPayrollRun(array $data): PayrollRun
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $duplicate = PayrollRun::query()->where('business_id', $businessId)->where('run_type', $data['run_type'])->whereDate('period_start', $data['period_start'])->whereDate('period_end', $data['period_end'])->whereIn('status', ['calculated', 'approved', 'posted'])->exists();
            if ($duplicate) throw ValidationException::withMessages(['period_start' => 'Payroll already exists for this period.']);
            $run = PayrollRun::query()->create(array_merge($data, ['business_id' => $businessId, 'run_number' => $this->nextNumber('PAY', PayrollRun::class, 'run_number'), 'created_by' => Auth::id(), 'status' => 'calculated']));
            $start = Carbon::parse($run->period_start); $end = Carbon::parse($run->period_end);
            $employees = Employee::query()->where('business_id', $businessId)->where('payroll_status', 'included')->where('status', 'active')->whereIn('employment_status', ['active', 'notice_period'])
                ->when($run->branch_id, fn (Builder $q) => $q->where('branch_id', $run->branch_id))->orderBy('id')->get();
            foreach ($employees as $employee) {
                $payroll = EmployeePayroll::query()->create(['business_id' => $businessId, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'status' => 'calculated']);
                $this->calculator->persist($payroll, $this->calculator->calculate($employee, $start, $end));
            }
            $this->refreshRunTotals($run);
            if (($data['status'] ?? 'calculated') === 'approved') $run->update(['status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => now()]);
            if (($data['status'] ?? 'calculated') === 'posted') $this->postPayroll($run->id);
            return $run->fresh(['employeePayrolls.employee', 'employeePayrolls.components']);
        });
    }

    public function postPayroll(int $id): PayrollRun
    {
        return DB::transaction(function () use ($id) {
            $run = PayrollRun::query()->where('business_id', AppController::businessId())->with('employeePayrolls.components')->findOrFail($id);
            if ($run->journal_voucher_id) return $run;
            $settings = $this->payrollAccounts($run->business_id);
            $entries = [];
            foreach ($run->employeePayrolls as $payroll) {
                foreach ($payroll->components as $line) {
                    $amount = (float) $line->amount;
                    if ($amount <= 0) continue;
                    if (in_array($line->component_type, ['earning', 'employer_contribution', 'reimbursement'], true)) {
                        $this->posting->addDebitEntry($entries, $settings['expense'], $amount, ['employee_id' => $payroll->employee_id, 'payroll_run_id' => $run->id, 'employee_payroll_id' => $payroll->id]);
                    }
                    if (in_array($line->component_type, ['deduction', 'employer_contribution'], true)) {
                        $this->posting->addCreditEntry($entries, $settings['statutory'], $amount, ['employee_id' => $payroll->employee_id, 'payroll_run_id' => $run->id, 'employee_payroll_id' => $payroll->id]);
                    }
                }
                $this->posting->addCreditEntry($entries, $settings['payable'], (float) $payroll->net_pay, ['employee_id' => $payroll->employee_id, 'payroll_run_id' => $run->id, 'employee_payroll_id' => $payroll->id, 'narration' => 'Salary payable']);
            }
            $journal = $this->posting->createJournalVoucher(['business_id' => $run->business_id, 'branch_id' => $run->branch_id, 'voucher_type' => 'payroll', 'voucher_date' => $run->payment_date ?: $run->period_end, 'reference_type' => PayrollRun::class, 'reference_id' => $run->id, 'reference_number' => $run->run_number, 'narration' => 'Payroll accrual posting', 'status' => 'approved', 'is_system_generated' => true, 'entries' => $entries]);
            $run->update(['status' => 'posted', 'journal_voucher_id' => $journal->id, 'approved_by' => Auth::id(), 'approved_at' => now()]);
            return $run->fresh('employeePayrolls');
        });
    }

    public function generatePayslips(int $runId): PayrollRun
    {
        return DB::transaction(function () use ($runId) {
            $run = PayrollRun::query()->where('business_id', AppController::businessId())->with('employeePayrolls')->findOrFail($runId);
            if (!in_array($run->status, ['approved', 'posted'], true)) throw ValidationException::withMessages(['status' => 'Approve or post payroll before final payslips.']);
            $prefix = $this->settings()->payslip_number_prefix ?: 'PS';
            foreach ($run->employeePayrolls as $idx => $payroll) {
                if (!$payroll->payslip_number) {
                    $payroll->update(['payslip_number' => $prefix . '-' . $run->run_number . '-' . str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT), 'payslip_generated_at' => now()]);
                }
            }
            return $run->fresh(['employeePayrolls.employee.department', 'employeePayrolls.components']);
        });
    }

    public function createPaymentBatch(array $data): PayrollPaymentBatch
    {
        return DB::transaction(function () use ($data) {
            $businessId = AppController::businessId();
            $run = PayrollRun::query()->where('business_id', $businessId)->with('employeePayrolls')->findOrFail($data['payroll_run_id']);
            if (!in_array($run->status, ['approved', 'posted'], true)) throw ValidationException::withMessages(['payroll_run_id' => 'Only approved or posted payroll can be paid.']);
            $batch = PayrollPaymentBatch::query()->create([
                'business_id' => $businessId, 'branch_id' => $run->branch_id, 'payroll_run_id' => $run->id, 'batch_number' => $this->nextNumber('PPB', PayrollPaymentBatch::class, 'batch_number'),
                'payment_date' => $data['payment_date'], 'payment_mode' => $data['payment_mode'] ?? 'bank_transfer', 'bank_account_id' => $data['bank_account_id'] ?? null,
                'employee_count' => $run->employeePayrolls->count(), 'total_amount' => $run->employeePayrolls->sum('net_pay'), 'status' => $data['status'] ?? 'draft', 'created_by' => Auth::id(),
            ]);
            foreach ($run->employeePayrolls as $payroll) {
                $batch->payments()->create(['business_id' => $businessId, 'employee_payroll_id' => $payroll->id, 'employee_id' => $payroll->employee_id, 'payable_amount' => $payroll->net_pay, 'paid_amount' => $batch->status === 'processed' ? $payroll->net_pay : 0, 'status' => $batch->status === 'processed' ? 'paid' : 'pending']);
            }
            if ($batch->status === 'processed') $this->postSalaryPayment($batch);
            return $batch->fresh('payments.employee');
        });
    }

    public function reports(): array
    {
        $businessId = AppController::businessId();
        return [
            'employee_register' => Employee::query()->with(['branch', 'department', 'designation'])->where('business_id', $businessId)->orderBy('employee_code')->get(),
            'payroll_register' => EmployeePayroll::query()->with(['run', 'employee.department', 'components'])->where('business_id', $businessId)->latest('id')->limit(500)->get(),
            'attendance_summary' => AttendanceRecord::query()->with('employee')->where('business_id', $businessId)->latest('attendance_date')->limit(500)->get(),
            'leave_summary' => LeaveRequest::query()->with(['employee', 'leaveType'])->where('business_id', $businessId)->latest('id')->limit(500)->get(),
            'loan_report' => EmployeeLoan::query()->with('employee')->where('business_id', $businessId)->latest('id')->limit(500)->get(),
            'reconciliation' => [
                'unposted_payroll_runs' => PayrollRun::query()->where('business_id', $businessId)->whereNull('journal_voucher_id')->whereIn('status', ['approved', 'posted'])->count(),
                'negative_net_pay' => EmployeePayroll::query()->where('business_id', $businessId)->where('net_pay', '<', 0)->count(),
                'missing_payslips' => EmployeePayroll::query()->where('business_id', $businessId)->whereNull('payslip_number')->count(),
            ],
        ];
    }

    public function saveAdvance(array $data): EmployeeAdvance
    {
        return DB::transaction(function () use ($data) {
            $employee = Employee::query()->where('business_id', AppController::businessId())->findOrFail($data['employee_id']);
            $advance = EmployeeAdvance::query()->create(array_merge($data, ['business_id' => $employee->business_id, 'outstanding_amount' => $data['amount'], 'created_by' => Auth::id()]));
            return $advance->fresh('employee');
        });
    }

    public function saveLoan(array $data): EmployeeLoan
    {
        return DB::transaction(function () use ($data) {
            $employee = Employee::query()->where('business_id', AppController::businessId())->findOrFail($data['employee_id']);
            $loan = EmployeeLoan::query()->create(array_merge($data, ['business_id' => $employee->business_id, 'loan_number' => $this->nextNumber('LOAN', EmployeeLoan::class, 'loan_number'), 'emi_amount' => round((float) $data['principal_amount'] / max(1, (int) $data['tenure_months']), 2), 'outstanding_amount' => $data['principal_amount'], 'created_by' => Auth::id()]));
            for ($i = 1; $i <= (int) $loan->tenure_months; $i++) {
                EmployeeLoanInstallment::query()->create(['business_id' => $employee->business_id, 'employee_loan_id' => $loan->id, 'due_date' => Carbon::parse($loan->loan_date)->addMonths($i)->format('Y-m-d'), 'principal_amount' => $loan->emi_amount, 'interest_amount' => 0, 'status' => 'pending']);
            }
            return $loan->fresh('employee', 'installments');
        });
    }

    private function refreshRunTotals(PayrollRun $run): void
    {
        $rows = EmployeePayroll::query()->where('payroll_run_id', $run->id)->get();
        $run->update(['employee_count' => $rows->count(), 'gross_earnings' => $rows->sum('gross_earnings'), 'total_deductions' => $rows->sum('total_deductions'), 'employer_contributions' => $rows->sum('employer_contributions'), 'reimbursements' => $rows->sum('reimbursements'), 'net_pay' => $rows->sum('net_pay')]);
    }

    private function payrollAccounts(int $businessId): array
    {
        $s = BusinessAccountSetting::query()->where('business_id', $businessId)->first();
        $expense = $this->firstAccount($businessId, [$s->salary_expense_account_id ?? null, $s->payroll_expense_account_id ?? null], ['expense']);
        $payable = $this->firstAccount($businessId, [$s->salary_payable_account_id ?? null, $s->accounts_payable_id ?? null], ['liability']);
        $statutory = $this->firstAccount($businessId, [$s->pf_payable_account_id ?? null, $s->professional_tax_payable_account_id ?? null, $s->accounts_payable_id ?? null], ['liability']);
        return ['expense' => $expense, 'payable' => $payable, 'statutory' => $statutory, 'payment' => $s->default_payment_account_id ?? $s->cash_account_id ?? null];
    }

    private function postSalaryPayment(PayrollPaymentBatch $batch): void
    {
        $accounts = $this->payrollAccounts($batch->business_id);
        $credit = $batch->bank_account_id ?: $accounts['payment'];
        if (!$credit) throw ValidationException::withMessages(['bank_account_id' => 'Payment account is required.']);
        $entries = [];
        $this->posting->addDebitEntry($entries, $accounts['payable'], (float) $batch->total_amount);
        $this->posting->addCreditEntry($entries, $credit, (float) $batch->total_amount);
        $journal = $this->posting->createJournalVoucher(['business_id' => $batch->business_id, 'branch_id' => $batch->branch_id, 'voucher_type' => 'payroll_payment', 'voucher_date' => $batch->payment_date, 'reference_type' => PayrollPaymentBatch::class, 'reference_id' => $batch->id, 'reference_number' => $batch->batch_number, 'narration' => 'Salary payment batch', 'status' => 'approved', 'is_system_generated' => true, 'entries' => $entries]);
        $batch->update(['journal_voucher_id' => $journal->id, 'processed_by' => Auth::id(), 'processed_at' => now()]);
    }

    private function firstAccount(int $businessId, array $ids, array $types): int
    {
        foreach ($ids as $id) if ($id) return (int) $id;
        $account = Account::query()->where('business_id', $businessId)->whereIn('account_type', $types)->first();
        if (!$account) throw ValidationException::withMessages(['account_settings' => 'Payroll account mappings are required.']);
        return (int) $account->id;
    }

    private function nextNumber(string $prefix, string $class, string $column): string
    {
        $businessId = AppController::businessId();
        $base = $prefix . '-' . date('Y') . '-';
        $last = $class::query()->where('business_id', $businessId)->where($column, 'like', $base . '%')->lockForUpdate()->orderByDesc('id')->value($column);
        return $base . str_pad((string) ($last ? ((int) substr($last, strlen($base)) + 1) : 1), 5, '0', STR_PAD_LEFT);
    }

    private function minutes(?string $start, ?string $end): int
    {
        if (!$start || !$end) return 0;
        return max(0, Carbon::parse($start)->diffInMinutes(Carbon::parse($end), false));
    }

    private function payableMinutes(string $status, int $minutes): int
    {
        if (in_array($status, ['absent', 'unpaid_leave', 'missing_punch'], true)) return 0;
        if ($status === 'half_day') return (int) round($minutes / 2);
        return $minutes;
    }
}
