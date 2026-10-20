<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use App\Models\Account;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer'],
            'category_code' => ['required', 'string', 'max:50', Rule::unique('expense_categories', 'category_code')->where('business_id', AppController::businessId())->ignore((int) $this->route('category'))],
            'category_name' => ['required', 'string', 'max:255', Rule::unique('expense_categories', 'category_name')->where('business_id', AppController::businessId())->ignore((int) $this->route('category'))],
            'expense_account_id' => ['required', 'integer', function ($attribute, $value, $fail) { if (!Account::query()->categoryPosting(AppController::businessId(), 'expense')->whereKey($value)->exists()) $fail('Select an active expense ledger for this business.'); }],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
