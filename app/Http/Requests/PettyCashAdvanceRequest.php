<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PettyCashAdvanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'employee_id' => ['required', 'integer'],
            'cash_account_id' => ['required', 'integer'],
            'advance_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'purpose' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:draft,issued'],
        ];
    }
}
