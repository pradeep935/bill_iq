<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrmLeadRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'lead_type' => ['required', Rule::in(['individual', 'business'])],
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['required', 'string', 'max:30'],
            'alternate_mobile' => ['nullable', 'string', 'max:30'],
            'whatsapp_number' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'lead_source_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'assigned_team_id' => ['nullable', 'integer'],
            'status_id' => ['nullable', 'integer'],
            'qualification_status' => ['nullable', Rule::in(['unqualified', 'marketing_qualified', 'sales_qualified', 'disqualified'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'expected_closing_date' => ['nullable', 'date'],
            'preferred_product_ids_json' => ['nullable', 'array'],
            'requirement_summary' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_id' => ['nullable', 'integer'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'country_id' => ['nullable', 'integer'],
            'gstin' => ['nullable', 'string', 'max:30'],
            'pan' => ['nullable', 'string', 'max:20'],
            'referral_name' => ['nullable', 'string', 'max:255'],
            'referral_contact' => ['nullable', 'string', 'max:255'],
            'do_not_call' => ['boolean'],
            'do_not_email' => ['boolean'],
            'do_not_whatsapp' => ['boolean'],
            'consent_source' => ['nullable', 'string'],
            'consent_at' => ['nullable', 'date'],
            'consent_notes' => ['nullable', 'string'],
            'lost_reason_id' => ['nullable', 'integer'],
            'lost_notes' => ['nullable', 'string'],
            'next_follow_up_at' => ['nullable', 'date'],
            'tags_json' => ['nullable', 'array'],
            'custom_fields_json' => ['nullable', 'array'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'contacts' => ['nullable', 'array'],
            'contacts.*.contact_name' => ['required_with:contacts', 'string'],
            'contacts.*.designation' => ['nullable', 'string'],
            'contacts.*.email' => ['nullable', 'email'],
            'contacts.*.mobile' => ['nullable', 'string'],
            'contacts.*.whatsapp_number' => ['nullable', 'string'],
            'contacts.*.is_primary' => ['boolean'],
            'contacts.*.notes' => ['nullable', 'string'],
        ];
    }
}
