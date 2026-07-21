<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BankReconciliationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer'],
            'statement_start_date' => ['nullable', 'date'],
            'statement_end_date' => ['nullable', 'date'],
            'statement_closing_balance' => ['required', 'numeric'],
            'items' => ['nullable', 'array'],
            'items.*.bank_statement_line_id' => ['nullable', 'integer'],
            'items.*.journal_entry_id' => ['nullable', 'integer'],
            'items.*.matched_amount' => ['required', 'numeric', 'min:0.01'],
            'items.*.match_type' => ['nullable', 'in:auto,manual,partial,one_to_many,many_to_one'],
            'items.*.remarks' => ['nullable', 'string'],
        ];
    }
}
