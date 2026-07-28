<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BomRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'finished_product_id' => ['required', 'integer'],
            'finished_product_variant_id' => ['nullable', 'integer'],
            'bom_code' => ['nullable', 'string', 'max:60'],
            'bom_name' => ['required', 'string', 'max:255'],
            'version' => ['nullable', 'integer', 'min:1'],
            'output_quantity' => ['required', 'numeric', 'min:0.001'],
            'unit_id' => ['nullable', 'integer'],
            'wastage_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'inactive'])],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.raw_material_product_id' => ['required', 'integer'],
            'items.*.raw_material_variant_id' => ['nullable', 'integer'],
            'items.*.quantity_required' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.wastage_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.warehouse_id' => ['nullable', 'integer'],
            'items.*.batch_selection_method' => ['nullable', Rule::in(['fefo', 'fifo', 'manual'])],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
