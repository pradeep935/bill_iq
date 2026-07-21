<?php

namespace App\Http\Requests;

use App\Http\Controllers\AppController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_type' => ['required', Rule::in(['retail', 'wholesale', 'dealer', 'distributor', 'corporate', 'walk_in'])],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', Rule::unique('customers')->where('business_id', $businessId)->ignore($customerId)],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'billing_address' => ['nullable', 'string', 'max:2000'],
            'shipping_address' => ['nullable', 'string', 'max:2000'],
            'state_id' => ['nullable', 'integer'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:20'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => ['nullable', Rule::in(['debit', 'credit'])],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0'],
            'price_type' => ['nullable', Rule::in(['retail', 'wholesale', 'dealer', 'online'])],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
