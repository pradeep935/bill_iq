<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $statusOnly = in_array($this->input('operation'), ['block', 'unblock'], true);
        $receipt = in_array($this->input('operation'), ['opening', 'stock_in'], true);

        return [
            'operation_token' => ['nullable', 'uuid'],
            'operation' => ['required', Rule::in(['opening', 'stock_in', 'adjust', 'transfer', 'reclassify', 'quarantine', 'release_quarantine', 'block', 'unblock', 'writeoff'])],
            'batch_id' => [$receipt ? 'nullable' : 'required', 'integer'],
            'product_id' => [Rule::requiredIf($receipt && ! $this->input('batch_id')), 'nullable', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'batch_number' => [Rule::requiredIf($receipt && ! $this->input('batch_id')), 'nullable', 'string', 'max:100'],
            'manufacturing_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'document_date' => ['nullable', 'date'],
            'branch_id' => [$statusOnly ? 'nullable' : 'required', 'integer'],
            'warehouse_id' => [$statusOnly ? 'nullable' : 'required', 'integer'],
            'quantity' => [$statusOnly ? 'nullable' : 'required', 'numeric', 'min:0.001'],
            'unit_cost' => [Rule::requiredIf($receipt || ($this->input('operation') === 'adjust' && $this->input('direction') === 'in')), 'nullable', 'numeric', 'min:0'],
            'condition_status' => [$statusOnly ? 'nullable' : 'required', Rule::in(['saleable', 'damaged', 'expired', 'defective', 'quarantined'])],
            'to_condition' => [Rule::requiredIf($this->input('operation') === 'reclassify'), 'nullable', Rule::in(['saleable', 'damaged', 'expired', 'defective', 'quarantined'])],
            'direction' => [Rule::requiredIf($this->input('operation') === 'adjust'), 'nullable', 'in:in,out'],
            'destination_branch_id' => ['required_if:operation,transfer', 'nullable', 'integer'],
            'destination_warehouse_id' => ['required_if:operation,transfer', 'nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
