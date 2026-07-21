<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecurringExpenseTemplateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'template_name' => ['required', 'string', 'max:255'],
            'expense_category_id' => ['required', 'integer'],
            'expense_account_id' => ['required', 'integer'],
            'paid_from_account_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'frequency' => ['required', 'in:daily,weekly,monthly,quarterly,half_yearly,yearly,custom'],
            'interval_value' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'next_run_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax_type' => ['required', 'in:exclusive,inclusive,non_taxable'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'auto_create_draft' => ['nullable', 'boolean'],
            'auto_post' => ['nullable', 'boolean'],
            'approval_required' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,paused,stopped'],
            'narration' => ['nullable', 'string'],
        ];
    }
}
