<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalaryStructureRequest extends FormRequest
{
    public function authorize(): bool { return AppController::canOpen('payroll'); }
    public function rules(): array
    {
        $businessId = AppController::businessId();
        return [
            'structure_code' => ['required', 'string', 'max:50'], 'structure_name' => ['required', 'string'], 'grade_id' => ['nullable', 'integer'],
            'employment_type' => ['nullable', 'string', 'max:40'], 'effective_from' => ['required', 'date'], 'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'annual_ctc' => ['nullable', 'numeric', 'min:0'], 'monthly_gross' => ['nullable', 'numeric', 'min:0'], 'description' => ['nullable', 'string'], 'status' => ['required', Rule::in(['draft', 'approved', 'inactive'])],
            'components' => ['required', 'array', 'min:1'], 'components.*.salary_component_id' => ['required', 'integer', Rule::exists('salary_components', 'id')->where('business_id', $businessId)],
            'components.*.calculation_type' => ['required', Rule::in(['fixed', 'percentage', 'formula', 'attendance_based', 'slab', 'manual'])],
            'components.*.fixed_amount' => ['nullable', 'numeric', 'min:0'], 'components.*.percentage' => ['nullable', 'numeric', 'min:0'], 'components.*.monthly_amount' => ['nullable', 'numeric'], 'components.*.annual_amount' => ['nullable', 'numeric'],
        ];
    }
}
