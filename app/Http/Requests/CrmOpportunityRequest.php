<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrmOpportunityRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'opportunity_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer'],
            'sales_team_id' => ['nullable', 'integer'],
            'source_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'currency_id' => ['nullable', 'integer'],
            'estimated_value' => ['required', 'numeric', 'min:0'],
            'probability_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'expected_closing_date' => ['nullable', 'date'],
            'actual_closing_date' => ['nullable', 'date'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'competitor_name' => ['nullable', 'string'],
            'next_step' => ['nullable', 'string'],
            'next_follow_up_at' => ['nullable', 'date'],
            'quotation_id' => ['nullable', 'integer'],
            'sales_order_id' => ['nullable', 'integer'],
            'won_reason' => ['nullable', 'string'],
            'lost_reason_id' => ['nullable', 'integer'],
            'lost_notes' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['open', 'won', 'lost', 'cancelled'])],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.description' => ['required_with:items', 'string'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.estimated_unit_price' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.estimated_discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.estimated_tax' => ['nullable', 'numeric', 'min:0'],
            'items.*.probability_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }
}
