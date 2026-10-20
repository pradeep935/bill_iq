<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class PettyCashAdvanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('business_id', AppController::businessId())],
            'employee_id' => ['bail', 'required', 'integer', function ($attribute, $value, $fail) {
                $valid = \App\Models\Employee::query()->where('business_id', AppController::businessId())
                    ->where('status', 'active')->where('employment_status', 'active')
                    ->when($this->filled('branch_id'), fn ($query) => $query->where('branch_id', $this->input('branch_id')))
                    ->whereKey($value)->exists();
                if (!$valid) $fail('Select an active employee from this business and the selected branch. The employee may have become inactive or unavailable.');
            }],
            'cash_account_id' => ['required', 'integer'],
            'advance_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'purpose' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:draft,issued'],
        ];
    }
}
