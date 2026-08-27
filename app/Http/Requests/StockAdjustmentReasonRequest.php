<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentReasonRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason_code' => strtoupper(trim((string) $this->input('reason_code', ''))),
            'reason_name' => trim((string) $this->input('reason_name', '')),
            'default_condition_status' => $this->filled('default_condition_status') ? trim((string) $this->input('default_condition_status')) : null,
            'default_direction' => trim((string) $this->input('default_direction', '')),
            'status' => trim((string) $this->input('status', '')),
        ]);
    }

    public function rules(): array
    {
        $reasonId = $this->route('reason');

        return [
            'reason_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('stock_adjustment_reasons', 'reason_code')
                    ->where('business_id', AppController::businessId())
                    ->ignore($reasonId)
                    ->withoutTrashed(),
            ],
            'reason_name' => ['required', 'string', 'max:255'],
            'default_direction' => ['required', 'in:in,out'],
            'default_condition_status' => ['required', 'in:saleable,damaged,expired,defective,quarantined,lost'],
            'accounting_account_id' => ['nullable', 'integer'],
            'approval_required' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
