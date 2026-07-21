<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BankStatementImportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer'],
            'file_name' => ['required', 'string', 'max:255'],
            'statement_start_date' => ['nullable', 'date'],
            'statement_end_date' => ['nullable', 'date'],
            'opening_balance' => ['nullable', 'numeric'],
            'closing_balance' => ['nullable', 'numeric'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.transaction_date' => ['required', 'date'],
            'lines.*.value_date' => ['nullable', 'date'],
            'lines.*.description' => ['required', 'string'],
            'lines.*.reference_number' => ['nullable', 'string', 'max:120'],
            'lines.*.cheque_number' => ['nullable', 'string', 'max:120'],
            'lines.*.debit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.running_balance' => ['nullable', 'numeric'],
            'lines.*.external_transaction_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}
