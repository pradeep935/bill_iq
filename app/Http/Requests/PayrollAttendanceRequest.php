<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayrollAttendanceRequest extends FormRequest
{
    public function authorize(): bool { return AppController::canOpen('payroll'); }
    public function rules(): array
    {
        $businessId = AppController::businessId();
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('business_id', $businessId)],
            'attendance_date' => ['required', 'date'], 'first_in_at' => ['nullable', 'date'], 'last_out_at' => ['nullable', 'date'],
            'attendance_status' => ['required', Rule::in(['present', 'absent', 'half_day', 'paid_leave', 'unpaid_leave', 'holiday', 'weekly_off', 'work_from_home', 'on_duty', 'travel', 'missing_punch'])],
            'shift_id' => ['nullable', 'integer'], 'source' => ['required', Rule::in(['manual', 'biometric', 'mobile', 'web', 'imported', 'mixed'])], 'remarks' => ['nullable', 'string'],
        ];
    }
}
