<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepreciationRunRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'financial_year' => ['required', 'string', 'max:20'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'posting_date' => ['required', 'date'],
            'status' => ['required', 'in:draft,calculated,approved,posted'],
        ];
    }
}
