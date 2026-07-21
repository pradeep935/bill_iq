<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer'],
            'category_code' => ['required', 'string', 'max:50'],
            'category_name' => ['required', 'string', 'max:255'],
            'expense_account_id' => ['required', 'integer'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
