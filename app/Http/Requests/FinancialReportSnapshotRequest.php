<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinancialReportSnapshotRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'report_type' => ['required', 'in:trial_balance,profit_and_loss,balance_sheet,cash_flow'],
            'financial_year' => ['nullable', 'string', 'max:20'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:draft,approved,final'],
        ];
    }
}
