<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustmentVoucherRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'adjustment_date' => ['required', 'date'],
            'adjustment_reason_id' => ['nullable', 'integer'],
            'adjustment_type' => ['required', 'in:increase,decrease,mixed'],
            'source' => ['required', 'in:manual,physical_count,damage,expiry,loss,theft,quality_rejection,opening_correction,system_correction'],
            'status' => ['required', 'in:draft,submitted,approved,posted'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.serial_id' => ['nullable', 'integer'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.actual_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.adjustment_quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.direction' => ['required', 'in:in,out'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.warehouse_location' => ['nullable', 'string', 'max:255'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
            'items.*.condition_status' => ['nullable', 'in:saleable,damaged,expired,defective,quarantined,lost'],
        ];
    }
}
