<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseVoucherRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer'],
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['required', 'integer'],
            'expense_account_id' => ['required', 'integer'],
            'paid_from_account_id' => ['nullable', 'integer'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'payment_mode' => ['required', 'in:cash,bank_transfer,UPI,card,cheque,wallet,employee_reimbursement,unpaid,mixed'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'tax_type' => ['required', 'in:exclusive,inclusive,non_taxable'],
            'tds_applicable' => ['nullable', 'boolean'],
            'tds_section_id' => ['nullable', 'integer'],
            'tds_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_status' => ['required', 'in:unpaid,partial,paid'],
            'status' => ['required', 'in:draft,submitted,approved,posted'],
            'narration' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.expense_category_id' => ['required', 'integer'],
            'items.*.expense_account_id' => ['required', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.hsn_sac_code' => ['nullable', 'string', 'max:40'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.rate' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.cess_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.cost_center_id' => ['nullable', 'integer'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }
}
