<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AccountClassificationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'target_type' => ['required', 'in:account,group'],
            'target_id' => ['required', 'integer'],
            'financial_statement_type' => ['nullable', 'in:balance_sheet,profit_and_loss,memorandum'],
            'financial_statement_section' => ['required', 'string', 'max:80'],
            'cash_flow_category' => ['nullable', 'in:operating,investing,financing,cash_equivalent,non_cash'],
            'report_order' => ['nullable', 'integer', 'min:1'],
            'normal_balance' => ['nullable', 'in:debit,credit'],
            'is_control_group' => ['nullable', 'boolean'],
            'is_control_account' => ['nullable', 'boolean'],
        ];
    }
}
