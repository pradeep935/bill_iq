<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FixedAssetRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'asset_category_id' => ['required', 'integer'],
            'asset_tag' => ['nullable', 'string', 'max:100'],
            'asset_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'manufacturer' => ['nullable', 'string'],
            'model_number' => ['nullable', 'string'],
            'serial_number' => ['nullable', 'string'],
            'barcode' => ['nullable', 'string'],
            'supplier_id' => ['nullable', 'integer'],
            'acquisition_date' => ['required', 'date'],
            'capitalization_date' => ['nullable', 'date'],
            'put_to_use_date' => ['nullable', 'date'],
            'purchase_cost' => ['required', 'numeric', 'min:0'],
            'additional_cost' => ['nullable', 'numeric', 'min:0'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'depreciation_method' => ['required', Rule::in(['straight_line', 'written_down_value', 'units_of_production', 'no_depreciation'])],
            'useful_life_months' => ['nullable', 'integer', 'min:1'],
            'depreciation_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'current_location_id' => ['nullable', 'integer'],
            'assigned_employee_id' => ['nullable', 'integer'],
            'ownership_type' => ['required', Rule::in(['owned', 'leased', 'rented', 'financed', 'customer_owned', 'third_party'])],
            'condition_status' => ['required', Rule::in(['new', 'good', 'fair', 'poor', 'damaged', 'under_repair', 'obsolete', 'scrapped'])],
            'asset_status' => ['required', Rule::in(['draft', 'pending_approval', 'active', 'inactive', 'under_maintenance', 'transferred', 'impaired', 'disposed', 'sold', 'written_off', 'lost', 'stolen'])],
            'warranty_start_date' => ['nullable', 'date'],
            'warranty_end_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
