<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockCountSessionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'count_date' => ['required', 'date'],
            'count_type' => ['required', 'in:full,cycle_count,category,brand,location,selected_products'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'warehouse_location_from' => ['nullable', 'string', 'max:255'],
            'warehouse_location_to' => ['nullable', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer'],
            'freeze_stock' => ['nullable', 'boolean'],
            'status' => ['required', 'in:draft,assigned,counting,submitted,reviewed,approved'],
            'remarks' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.serial_id' => ['nullable', 'integer'],
            'items.*.counted_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.warehouse_location' => ['nullable', 'string', 'max:255'],
            'items.*.review_status' => ['nullable', 'in:pending,accepted,rejected,recount_required'],
            'items.*.reviewer_notes' => ['nullable', 'string'],
        ];
    }
}
