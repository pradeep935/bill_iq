<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use App\Services\MobileNumberService;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->isAdmin());
    }

    public function rules(): array
    {
        $businessId = AppController::businessId();
        $customerId = (int) ($this->route('customer') ?: 0);

        return [
            'customer_code' => ['nullable', 'string', 'max:50', Rule::unique('customers')->where('business_id', $businessId)->ignore($customerId)],
            'customer_name' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'customer_type' => ['required', Rule::in(['retail', 'wholesale', 'dealer', 'distributor', 'corporate', 'government', 'export', 'walk_in', 'other'])],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'regex:/^[6-9][0-9]{9}$/'],
            'whatsapp_number' => ['nullable', 'regex:/^[6-9][0-9]{9}$/'],
            'whatsapp_same_as_mobile' => ['nullable', 'boolean'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', Rule::unique('customers')->where('business_id', $businessId)->ignore($customerId)],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'shipping_address' => ['nullable', 'string', 'max:2000'],
            'state_id' => ['nullable', 'integer'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'regex:/^[0-9]{6}$/'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => ['nullable', Rule::in(['debit', 'credit'])],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0'],
            'price_type' => ['nullable', Rule::in(['retail', 'wholesale', 'dealer', 'distributor', 'online', 'other'])],
            'status' => ['required', Rule::in(['active', 'inactive', 'blocked'])],
            'blocked_reason' => ['nullable', 'required_if:status,blocked', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalizer = app(MobileNumberService::class);
        $mobile = $this->input('mobile') !== null ? $normalizer->normalize((string) $this->input('mobile')) : null;
        $sameAsMobile = filter_var($this->input('whatsapp_same_as_mobile', true), FILTER_VALIDATE_BOOLEAN);
        $whatsapp = $sameAsMobile
            ? $mobile
            : ($this->input('whatsapp_number') !== null ? $normalizer->normalize((string) $this->input('whatsapp_number')) : null);

        $this->merge([
            'customer_code' => $this->filled('customer_code') ? trim((string) $this->input('customer_code')) : null,
            'customer_name' => trim((string) $this->input('customer_name')),
            'contact_person' => $this->filled('contact_person') ? trim((string) $this->input('contact_person')) : null,
            'mobile' => $mobile,
            'whatsapp_number' => $whatsapp,
            'whatsapp_same_as_mobile' => $sameAsMobile,
            'email' => $this->filled('email') ? strtolower(trim((string) $this->input('email'))) : null,
            'gstin' => $this->filled('gstin') ? strtoupper(trim((string) $this->input('gstin'))) : null,
            'pan' => $this->filled('pan') ? strtoupper(trim((string) $this->input('pan'))) : null,
            'city' => $this->filled('city') ? trim((string) $this->input('city')) : null,
            'pincode' => $this->filled('pincode') ? trim((string) $this->input('pincode')) : null,
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $gstin = (string) $this->input('gstin');
            $pan = (string) $this->input('pan');

            if ($gstin && $pan && substr($gstin, 2, 10) !== $pan) {
                $validator->errors()->add('pan', 'PAN must match the PAN segment in GSTIN.');
            }

            if ($gstin && $this->filled('state_id') && Schema::hasTable('states')) {
                $state = DB::table('states')->where('id', $this->input('state_id'))->first();
                $stateCode = $state->gst_code ?? $state->lgd_code ?? $state->code ?? null;
                if ($stateCode && ctype_digit((string) $stateCode) && str_pad((string) $stateCode, 2, '0', STR_PAD_LEFT) !== substr($gstin, 0, 2)) {
                    $validator->errors()->add('gstin', 'GSTIN state code does not match selected state.');
                }
            }
        });
    }
}
