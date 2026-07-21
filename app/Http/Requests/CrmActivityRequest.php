<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrmActivityRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array
    {
        return [
            'activity_type' => ['required', Rule::in(['call', 'email', 'whatsapp', 'sms', 'meeting', 'task', 'note', 'demo', 'site_visit', 'follow_up', 'document_shared', 'status_change', 'assignment', 'quotation', 'sales_order', 'invoice', 'payment'])],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'related_type' => ['required', Rule::in(['lead', 'opportunity', 'customer'])],
            'related_id' => ['required', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'opportunity_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'assigned_to' => ['nullable', 'integer'],
            'activity_date' => ['required', 'date'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])],
            'outcome' => ['nullable', 'string'],
            'next_action' => ['nullable', 'string'],
            'next_follow_up_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['planned', 'in_progress', 'completed', 'cancelled', 'missed', 'overdue'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'location' => ['nullable', 'string'],
            'meeting_mode' => ['nullable', Rule::in(['in_person', 'phone', 'video', 'online'])],
            'external_reference' => ['nullable', 'string'],
            'reminder_at' => ['nullable', 'date'],
            'reminder_channel' => ['nullable', Rule::in(['in_app', 'email', 'sms', 'whatsapp', 'push_placeholder'])],
        ];
    }
}
