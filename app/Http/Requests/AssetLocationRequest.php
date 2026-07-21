<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetLocationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'location_code' => ['required', 'string', 'max:50'],
            'location_name' => ['required', 'string', 'max:255'],
            'location_type' => ['required', Rule::in(['branch', 'warehouse', 'office', 'floor', 'room', 'rack', 'employee_location', 'external_location'])],
            'floor' => ['nullable', 'string'],
            'room' => ['nullable', 'string'],
            'rack' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
