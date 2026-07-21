<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayrollRunRequest extends FormRequest
{
    public function authorize(): bool { return AppController::canOpen('payroll'); }
    public function rules(): array
    {
        $businessId = AppController::businessId();
        return [
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'payment_date' => ['nullable', 'date'],
            'financial_year' => ['nullable', 'string', 'max:20'], 'run_type' => ['required', Rule::in(['regular', 'supplementary', 'arrear', 'settlement'])],
            'status' => ['required', Rule::in(['draft', 'calculated', 'approved', 'posted'])],
        ];
    }
}
