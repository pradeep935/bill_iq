<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductionCompleteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'produced_quantity' => ['required', 'numeric', 'min:0.001'],
            'rejected_quantity' => ['nullable', 'numeric', 'min:0'],
            'additional_cost' => ['nullable', 'numeric', 'min:0'],
            'finished_batch_number' => ['nullable', 'string', 'max:100'],
            'manufacturing_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:manufacturing_date'],
            'items' => ['nullable', 'array'],
        ];
    }
}
