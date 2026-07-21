<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LocationTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'movement_date' => ['required', 'date'],
            'status' => ['required', 'in:draft,approved,posted'],
            'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.batch_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.from_location' => ['required', 'string', 'max:255'],
            'items.*.to_location' => ['required', 'string', 'max:255'],
        ];
    }
}
