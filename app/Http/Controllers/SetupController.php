<?php

namespace App\Http\Controllers;

use App\Services\Admin\AdminWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SetupController extends Controller
{
    public function branches()
    {
        if ($redirect = AppController::guardPage('masters')) return $redirect;

        return Inertia::render('Setup/Masters', [
            'page' => 'branches',
            'title' => 'Branches',
            'initial_tab' => 'branch',
        ]);
    }

    public function employees()
    {
        if ($redirect = AppController::guardPage('employees')) return $redirect;
        return Inertia::render('Payroll/Index', ['page' => 'employees', 'title' => 'Employees', 'initial_tab' => 'employees']);
    }

    public function users(Request $request, AdminWorkspaceService $workspace)
    {
        if ($redirect = AppController::guardPage('users')) return $redirect;

        return Inertia::render('Setup/Workspace', array_merge([
            'page' => 'users',
            'title' => 'Users & Roles',
            'initial_tab' => 'users',
        ], $workspace->build(AppController::businessId(), $request->user(), 'users')));
    }

    public function saas(Request $request, AdminWorkspaceService $workspace)
    {
        if ($redirect = AppController::guardPage('settings')) return $redirect;

        return Inertia::render('Setup/Workspace', array_merge([
            'page' => 'saas',
            'title' => 'SaaS Admin',
            'initial_tab' => 'saas',
        ], $workspace->build(AppController::businessId(), $request->user(), 'saas')));
    }

    public function settings(Request $request, AdminWorkspaceService $workspace)
    {
        if ($redirect = AppController::guardPage('settings')) return $redirect;

        return Inertia::render('Setup/Workspace', array_merge([
            'page' => 'settings',
            'title' => 'Settings',
            'initial_tab' => 'settings',
            'businessProfile' => $this->businessProfile(),
        ], $workspace->build(AppController::businessId(), $request->user(), 'settings')));
    }

    public function updateBusinessProfile(Request $request)
    {
        if ($redirect = AppController::guardPage('settings')) return $redirect;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'state' => ['nullable', 'string', 'max:120'],
            'financial_year' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:2000'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:60'],
            'bank_ifsc' => ['nullable', 'string', 'max:30'],
            'bank_account_holder' => ['nullable', 'string', 'max:255'],
            'invoice_terms' => ['nullable', 'string', 'max:3000'],
            'default_print_format' => ['nullable', 'in:a4,thermal'],
            'thermal_paper_width' => ['nullable', 'in:58mm,80mm'],
            'auto_print_after_payment' => ['nullable', 'boolean'],
            'show_logo_on_invoice' => ['nullable', 'boolean'],
            'show_logo_on_thermal_receipt' => ['nullable', 'boolean'],
            'thermal_footer_text' => ['nullable', 'string', 'max:255'],
            'a4_print_options' => ['nullable', 'array'],
            'a4_print_options.show_business_info' => ['nullable', 'boolean'],
            'a4_print_options.show_customer_info' => ['nullable', 'boolean'],
            'a4_print_options.show_hsn' => ['nullable', 'boolean'],
            'a4_print_options.show_tax_summary' => ['nullable', 'boolean'],
            'a4_print_options.show_bank_details' => ['nullable', 'boolean'],
            'a4_print_options.show_terms' => ['nullable', 'boolean'],
            'a4_print_options.show_signature' => ['nullable', 'boolean'],
            'thermal_print_options' => ['nullable', 'array'],
            'thermal_print_options.show_business_info' => ['nullable', 'boolean'],
            'thermal_print_options.show_gstin' => ['nullable', 'boolean'],
            'thermal_print_options.show_customer' => ['nullable', 'boolean'],
            'thermal_print_options.show_item_savings' => ['nullable', 'boolean'],
            'thermal_print_options.show_tax_breakup' => ['nullable', 'boolean'],
            'thermal_print_options.show_payment_details' => ['nullable', 'boolean'],
        ]);

        $businessId = AppController::businessId();
        abort_unless(Schema::hasTable('companies') && DB::table('companies')->where('id', $businessId)->exists(), 404);

        $payload = collect($data)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->map(fn ($value, $key) => $key === 'gstin' && $value ? strtoupper($value) : $value)
            ->map(fn ($value, $key) => in_array($key, ['a4_print_options', 'thermal_print_options'], true) ? json_encode($value) : $value)
            ->filter(fn ($value, $key) => Schema::hasColumn('companies', $key))
            ->all();

        DB::table('companies')->where('id', $businessId)->update(array_merge($payload, ['updated_at' => now()]));

        return response()->json([
            'message' => 'Business profile saved.',
            'businessProfile' => $this->businessProfile(),
        ]);
    }

    private function businessProfile(): array
    {
        $businessId = AppController::businessId();
        $company = Schema::hasTable('companies') ? DB::table('companies')->where('id', $businessId)->first() : null;
        $value = fn (string $key, $fallback = '') => $company && property_exists($company, $key) && $company->{$key} !== null ? $company->{$key} : $fallback;

        return [
            'name' => $value('name'),
            'gstin' => $value('gstin'),
            'state' => $value('state'),
            'financial_year' => $value('financial_year'),
            'address' => $value('address'),
            'logo_path' => $value('logo_path'),
            'logo_url' => $this->publicFileUrl($value('logo_path')),
            'phone' => $value('phone'),
            'email' => $value('email'),
            'bank_name' => $value('bank_name'),
            'bank_account_number' => $value('bank_account_number'),
            'bank_ifsc' => $value('bank_ifsc'),
            'bank_account_holder' => $value('bank_account_holder'),
            'invoice_terms' => $value('invoice_terms'),
            'default_print_format' => $value('default_print_format', 'a4'),
            'thermal_paper_width' => $value('thermal_paper_width', '80mm'),
            'auto_print_after_payment' => (bool) $value('auto_print_after_payment', false),
            'show_logo_on_invoice' => (bool) $value('show_logo_on_invoice', true),
            'show_logo_on_thermal_receipt' => (bool) $value('show_logo_on_thermal_receipt', false),
            'thermal_footer_text' => $value('thermal_footer_text', 'Thank You'),
            'a4_print_options' => $this->printOptions($value('a4_print_options'), $this->defaultA4PrintOptions()),
            'thermal_print_options' => $this->printOptions($value('thermal_print_options'), $this->defaultThermalPrintOptions()),
        ];
    }

    private function printOptions($stored, array $defaults): array
    {
        if (is_string($stored) && $stored !== '') {
            $stored = json_decode($stored, true);
        }

        return array_merge($defaults, is_array($stored) ? array_intersect_key($stored, $defaults) : []);
    }

    private function defaultA4PrintOptions(): array
    {
        return [
            'show_business_info' => true,
            'show_customer_info' => true,
            'show_hsn' => true,
            'show_tax_summary' => true,
            'show_bank_details' => true,
            'show_terms' => true,
            'show_signature' => true,
        ];
    }

    private function defaultThermalPrintOptions(): array
    {
        return [
            'show_business_info' => true,
            'show_gstin' => true,
            'show_customer' => true,
            'show_item_savings' => true,
            'show_tax_breakup' => true,
            'show_payment_details' => true,
        ];
    }

    private function publicFileUrl(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) || substr($path, 0, 1) === '/') {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
