<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductionOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'bom_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'source_warehouse_id' => ['required', 'integer'],
            'finished_goods_warehouse_id' => ['required', 'integer'],
            'planned_quantity' => ['required', 'numeric', 'min:0.001'],
            'produced_quantity' => ['nullable', 'numeric', 'min:0'],
            'rejected_quantity' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'expected_completion_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['draft', 'planned'])],
            'assigned_user_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'additional_cost' => ['nullable', 'numeric', 'min:0'],
            'manufacturing_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:manufacturing_date'],
        ];
    }
}
