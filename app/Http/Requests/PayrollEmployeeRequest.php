<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayrollEmployeeRequest extends FormRequest
{
    public function authorize(): bool { return AppController::canOpen('payroll') || AppController::canOpen('employees'); }

    public function rules(): array
    {
        $businessId = AppController::businessId();
        $employeeId = (int) ($this->route('employee') ?: 0);
        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'employee_code' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_code')->where('business_id', $businessId)->ignore($employeeId)],
            'user_id' => ['nullable', 'integer', Rule::unique('employees', 'user_id')->where('business_id', $businessId)->whereNull('deleted_at')->ignore($employeeId)],
            'title' => ['nullable', 'string', 'max:20'], 'first_name' => ['required', 'string', 'max:255'], 'middle_name' => ['nullable', 'string', 'max:255'], 'last_name' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'], 'gender' => ['nullable', 'string', 'max:30'], 'date_of_birth' => ['nullable', 'date'],
            'mobile' => ['required', 'string', 'max:30'], 'alternate_mobile' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'personal_email' => ['nullable', 'email'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('business_id', $businessId)],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')->where('business_id', $businessId)],
            'reporting_manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('business_id', $businessId)],
            'employment_type' => ['required', Rule::in(['permanent', 'probation', 'contract', 'temporary', 'intern', 'consultant', 'part_time', 'daily_wage'])],
            'employment_status' => ['required', Rule::in(['active', 'notice_period', 'resigned', 'terminated', 'retired', 'absconded', 'inactive'])],
            'joining_date' => ['required', 'date'], 'last_working_date' => ['nullable', 'date', 'after_or_equal:joining_date'],
            'payroll_status' => ['required', Rule::in(['included', 'excluded', 'on_hold'])],
            'payment_mode' => ['required', Rule::in(['bank_transfer', 'cash', 'cheque', 'other'])],
            'bank_account_name' => ['nullable', 'string'], 'bank_account_number' => ['nullable', 'string'], 'bank_name' => ['nullable', 'string'], 'bank_ifsc' => ['nullable', 'string', 'max:20'],
            'pan' => ['nullable', 'string', 'max:20'], 'aadhaar_masked' => ['nullable', 'string', 'max:20'], 'uan' => ['nullable', 'string', 'max:30'], 'pf_number' => ['nullable', 'string', 'max:30'], 'esi_number' => ['nullable', 'string', 'max:30'],
            'tax_regime' => ['nullable', 'string', 'max:30'], 'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
