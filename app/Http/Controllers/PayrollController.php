<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayrollAttendanceRequest;
use App\Http\Requests\PayrollEmployeeRequest;
use App\Http\Requests\PayrollRunRequest;
use App\Http\Requests\SalaryStructureRequest;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PayrollController extends Controller
{
    private PayrollService $payroll;

    public function __construct(PayrollService $payroll)
    {
        $this->payroll = $payroll;
    }

    public function index()
    {
        if ($redirect = AppController::guardPage('payroll')) return $redirect;
        return Inertia::render('Payroll/Index', ['page' => 'payroll', 'title' => 'Payroll']);
    }

    public function references() { abort_unless(AppController::canOpen('payroll'), 403); return response()->json($this->payroll->references()); }
    public function dashboard() { abort_unless(AppController::canOpen('payroll'), 403); return response()->json($this->payroll->dashboard()); }
    public function reports() { abort_unless(AppController::canOpen('payroll'), 403); return response()->json($this->payroll->reports()); }
    public function settings(Request $request) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['message' => 'Payroll settings saved.', 'settings' => $this->payroll->saveSettings($request->all())]); }
    public function employees(Request $request) { abort_unless(AppController::canOpen('payroll') || AppController::canOpen('employees'), 403); return response()->json(['employees' => $this->payroll->employees($request->all())]); }
    public function saveEmployee(PayrollEmployeeRequest $request, ?int $employee = null) { return response()->json(['message' => 'Employee saved.', 'employee' => $this->payroll->saveEmployee($request->validated(), $employee)], $employee ? 200 : 201); }
    public function saveMaster(Request $request, string $type, ?int $id = null) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['message' => 'Master saved.', 'record' => $this->payroll->saveMaster($type, $request->all(), $id)], $id ? 200 : 201); }
    public function attendance(Request $request) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['attendance' => $this->payroll->attendance($request->all())]); }
    public function saveAttendance(PayrollAttendanceRequest $request, ?int $attendance = null) { return response()->json(['message' => 'Attendance saved.', 'attendance' => $this->payroll->saveAttendance($request->validated(), $attendance)], $attendance ? 200 : 201); }
    public function saveSalaryStructure(SalaryStructureRequest $request, ?int $structure = null) { return response()->json(['message' => 'Salary structure saved.', 'structure' => $this->payroll->saveSalaryStructure($request->validated(), $structure)], $structure ? 200 : 201); }
    public function assignSalary(Request $request) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['message' => 'Salary assigned.', 'assignment' => $this->payroll->assignSalary($request->validate(['employee_id' => ['required', 'integer'], 'salary_structure_id' => ['required', 'integer'], 'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date'], 'annual_ctc' => ['nullable', 'numeric'], 'monthly_gross' => ['nullable', 'numeric'], 'monthly_net_estimate' => ['nullable', 'numeric'], 'reason' => ['nullable', 'string']]))], 201); }
    public function runs(Request $request) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['runs' => $this->payroll->payrollRuns($request->all())]); }
    public function createRun(PayrollRunRequest $request) { return response()->json(['message' => 'Payroll calculated.', 'run' => $this->payroll->createPayrollRun($request->validated())], 201); }
    public function postRun(int $run) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['message' => 'Payroll posted.', 'run' => $this->payroll->postPayroll($run)]); }
    public function payslips(int $run) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['message' => 'Payslips generated.', 'run' => $this->payroll->generatePayslips($run)]); }
    public function paymentBatch(Request $request) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['message' => 'Payroll payment batch saved.', 'batch' => $this->payroll->createPaymentBatch($request->validate(['payroll_run_id' => ['required', 'integer'], 'payment_date' => ['required', 'date'], 'payment_mode' => ['required', 'string'], 'bank_account_id' => ['nullable', 'integer'], 'status' => ['required', 'in:draft,approved,processed']]))], 201); }
    public function advance(Request $request) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['message' => 'Employee advance saved.', 'advance' => $this->payroll->saveAdvance($request->validate(['employee_id' => ['required', 'integer'], 'advance_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'min:0.01'], 'payment_account_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string'], 'status' => ['required', 'in:draft,approved,paid,closed,cancelled']]))], 201); }
    public function loan(Request $request) { abort_unless(AppController::canOpen('payroll'), 403); return response()->json(['message' => 'Employee loan saved.', 'loan' => $this->payroll->saveLoan($request->validate(['employee_id' => ['required', 'integer'], 'loan_date' => ['required', 'date'], 'principal_amount' => ['required', 'numeric', 'min:0.01'], 'interest_rate' => ['nullable', 'numeric', 'min:0'], 'interest_method' => ['required', 'in:flat,reducing_balance,interest_free'], 'tenure_months' => ['required', 'integer', 'min:1'], 'payment_account_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string'], 'status' => ['required', 'in:draft,approved,disbursed,active,closed,cancelled']]))], 201); }
}
