<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeePayroll;
use App\Models\EmployeeSalaryAssignment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollCalculationService
{
    public function calculate(Employee $employee, Carbon $start, Carbon $end): array
    {
        $calendarDays = max(1, $start->diffInDays($end) + 1);
        $attendance = $this->attendance($employee->id, $start, $end, $calendarDays);
        $assignment = EmployeeSalaryAssignment::query()
            ->where('business_id', $employee->business_id)
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $end->format('Y-m-d'))
            ->where(function ($q) use ($start) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start->format('Y-m-d'));
            })
            ->with('components.component')
            ->latest('effective_from')
            ->first();

        $exceptions = [];
        if (!$assignment) {
            $exceptions[] = 'Missing salary structure';
            return $this->emptyResult($calendarDays, $attendance, $exceptions);
        }

        $gross = $deductions = $employer = $reimbursements = 0.0;
        $lines = [];
        foreach ($assignment->components as $item) {
            $component = $item->component;
            if (!$component) continue;
            $amount = (float) $item->monthly_amount;
            if ($component->attendance_dependent || $component->prorate_on_joining || $component->prorate_on_exit) {
                $amount = round($amount * ((float) $attendance['paid_days'] / $calendarDays), 2);
            }
            if ($component->component_type === 'deduction') $deductions += $amount;
            elseif ($component->component_type === 'employer_contribution') $employer += $amount;
            elseif ($component->component_type === 'reimbursement') $reimbursements += $amount;
            else $gross += $amount;

            $lines[] = [
                'salary_component_id' => $component->id,
                'component_code' => $component->component_code,
                'component_name' => $component->component_name,
                'component_type' => $component->component_type,
                'amount' => $amount,
                'statutory' => (bool) $component->statutory,
                'employer_contribution' => (bool) $component->employer_contribution,
                'snapshot_json' => [
                    'calculation_type' => $item->calculation_type,
                    'monthly_amount' => (float) $item->monthly_amount,
                    'annual_amount' => (float) $item->annual_amount,
                    'attendance_dependent' => (bool) $component->attendance_dependent,
                ],
            ];
        }

        $net = round($gross + $reimbursements - $deductions, 2);
        if ($net < 0) $exceptions[] = 'Negative net pay';
        if ($employee->payment_mode === 'bank_transfer' && (!$employee->bank_account_number || !$employee->bank_ifsc)) $exceptions[] = 'Missing bank details';

        return [
            'salary_assignment_id' => $assignment->id,
            'calendar_days' => $calendarDays,
            'paid_days' => $attendance['paid_days'],
            'unpaid_days' => $attendance['unpaid_days'],
            'overtime_hours' => $attendance['overtime_hours'],
            'gross_earnings' => round($gross, 2),
            'total_deductions' => round($deductions, 2),
            'employer_contributions' => round($employer, 2),
            'reimbursements' => round($reimbursements, 2),
            'net_pay' => $net,
            'exceptions_json' => $exceptions,
            'components' => $lines,
        ];
    }

    public function persist(EmployeePayroll $payroll, array $result): EmployeePayroll
    {
        $lines = $result['components'] ?? [];
        unset($result['components']);
        $payroll->fill($result)->save();
        $payroll->components()->delete();
        foreach ($lines as $line) {
            $payroll->components()->create(array_merge($line, ['business_id' => $payroll->business_id]));
        }
        return $payroll->fresh(['employee.department', 'employee.designation', 'components']);
    }

    private function attendance(int $employeeId, Carbon $start, Carbon $end, int $calendarDays): array
    {
        $rows = DB::table('attendance_records')->where('employee_id', $employeeId)->whereBetween('attendance_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])->get();
        if ($rows->isEmpty()) return ['paid_days' => $calendarDays, 'unpaid_days' => 0, 'overtime_hours' => 0];
        $paid = $unpaid = $overtime = 0.0;
        foreach ($rows as $row) {
            if (in_array($row->attendance_status, ['present', 'paid_leave', 'holiday', 'weekly_off', 'work_from_home', 'on_duty', 'travel'], true)) $paid += 1;
            elseif ($row->attendance_status === 'half_day') { $paid += 0.5; $unpaid += 0.5; }
            else $unpaid += 1;
            $overtime += ((float) $row->overtime_minutes / 60);
        }
        $missing = max(0, $calendarDays - $rows->count());
        return ['paid_days' => round($paid + $missing, 2), 'unpaid_days' => round($unpaid, 2), 'overtime_hours' => round($overtime, 2)];
    }

    private function emptyResult(int $calendarDays, array $attendance, array $exceptions): array
    {
        return [
            'salary_assignment_id' => null, 'calendar_days' => $calendarDays, 'paid_days' => $attendance['paid_days'], 'unpaid_days' => $attendance['unpaid_days'], 'overtime_hours' => $attendance['overtime_hours'],
            'gross_earnings' => 0, 'total_deductions' => 0, 'employer_contributions' => 0, 'reimbursements' => 0, 'net_pay' => 0, 'exceptions_json' => $exceptions, 'components' => [],
        ];
    }
}
