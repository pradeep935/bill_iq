<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinancialYearClosureRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'financial_year' => ['required', 'string', 'max:20'],
            'closing_date' => ['required', 'date'],
            'retained_earnings_account_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,under_review,closed'],
        ];
    }
}
