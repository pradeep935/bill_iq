<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetCategoryRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'category_code' => ['required', 'string', 'max:50'],
            'category_name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
            'asset_account_id' => ['required', 'integer'],
            'accumulated_depreciation_account_id' => ['required', 'integer'],
            'depreciation_expense_account_id' => ['required', 'integer'],
            'default_depreciation_method' => ['required', Rule::in(['straight_line', 'written_down_value', 'units_of_production', 'no_depreciation'])],
            'default_useful_life_months' => ['nullable', 'integer', 'min:1'],
            'default_depreciation_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'default_residual_value_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'capitalisation_threshold' => ['nullable', 'numeric', 'min:0'],
            'maintenance_expense_account_id' => ['nullable', 'integer'],
            'impairment_loss_account_id' => ['nullable', 'integer'],
            'profit_on_sale_account_id' => ['nullable', 'integer'],
            'loss_on_sale_account_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
