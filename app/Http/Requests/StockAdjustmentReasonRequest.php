<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentReasonRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'max:50'],
            'reason_name' => ['required', 'string', 'max:255'],
            'default_direction' => ['required', 'in:in,out'],
            'default_condition_status' => ['nullable', 'in:saleable,damaged,expired,defective,quarantined,lost'],
            'accounting_account_id' => ['nullable', 'integer'],
            'approval_required' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
