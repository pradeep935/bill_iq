<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'], 'requisition_date' => ['required', 'date'], 'department' => ['nullable', 'string'],
            'requester_id' => ['nullable', 'integer'], 'priority' => ['required', 'in:normal,urgent,critical,low,high'], 'required_date' => ['nullable', 'date'],
            'status' => ['required', 'in:draft,submitted,pending_approval,approved,rejected,converted_to_po'], 'remarks' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_id' => ['required', 'integer'], 'items.*.unit_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'], 'items.*.approved_quantity' => ['nullable', 'numeric', 'min:0'], 'items.*.remarks' => ['nullable', 'string'],
        ];
    }
}
