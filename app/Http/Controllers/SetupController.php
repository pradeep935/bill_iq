<?php

namespace App\Http\Controllers;

use App\Services\Admin\AdminWorkspaceService;
use App\Services\CompanyOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
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

    public function companyOnboarding(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);

        $draft = [
            'legal_name' => 'PHOOLTAS TRANSRAIL LIMITED',
            'gstin' => '05AABCP5818M1Z7',
            'state_code' => '05',
            'state' => 'Uttarakhand',
            'financial_year' => '2026-27',
            'period_start_date' => '2026-04-01',
            'period_end_date' => '2027-03-31',
            'default_branch_name' => 'Head Office',
            'default_branch_code' => 'HO',
            'default_warehouse_name' => 'Main Warehouse',
            'default_warehouse_code' => 'MW',
            'registered_address' => '',
        ];

        return Inertia::render('Setup/CompanyOnboarding', [
            'page' => 'company-onboarding',
            'title' => 'Company Onboarding',
            'draft' => $draft,
            'users' => Schema::hasTable('users')
                ? DB::table('users')->where(function ($query) {
                    $query->whereNull('status')->orWhere('status', 'active');
                })->orderBy('name')->get(['id', 'name', 'role_id'])
                : [],
            'verification' => $this->draftVerification($draft),
        ]);
    }

    public function validateCompanyDraft(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);

        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'gstin' => ['required', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'state' => ['required', 'string', 'max:120'],
            'state_code' => ['required', 'string', 'size:2'],
            'financial_year' => ['required', 'string', 'max:30', 'regex:/^\d{4}-\d{2}$/'],
            'period_start_date' => ['required', 'date'],
            'period_end_date' => ['required', 'date', 'after_or_equal:period_start_date'],
            'default_branch_name' => ['required', 'string', 'max:255'],
            'default_branch_code' => ['nullable', 'string', 'max:50'],
            'default_warehouse_name' => ['required', 'string', 'max:255'],
            'default_warehouse_code' => ['nullable', 'string', 'max:50'],
            'registered_address' => ['nullable', 'string', 'max:2000'],
            'authorized_user_ids' => ['nullable', 'array'],
            'authorized_user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        return response()->json([
            'message' => 'Draft validated. Company creation still requires explicit approval.',
            'verification' => $this->draftVerification($data),
            'payload' => $data,
        ]);
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

    public function storeCompany(Request $request, CompanyOnboardingService $onboarding)
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);

        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'state' => ['required', 'string', 'max:120'],
            'financial_year' => ['required', 'string', 'max:30', 'regex:/^\d{4}-\d{2}$/'],
            'period_start_date' => ['nullable', 'date'],
            'period_end_date' => ['nullable', 'date', 'after_or_equal:period_start_date'],
            'accounting_period_status' => ['nullable', Rule::in(['open', 'active'])],
            'default_branch_name' => ['required', 'string', 'max:255'],
            'default_branch_code' => ['nullable', 'string', 'max:50'],
            'default_warehouse_name' => ['required', 'string', 'max:255'],
            'default_warehouse_code' => ['nullable', 'string', 'max:50'],
            'authorized_user_ids' => ['nullable', 'array'],
            'authorized_user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        $result = $onboarding->create($data, $user);

        return response()->json([
            'message' => 'Company created successfully. Review legal verification before importing client data.',
            'company' => $result['company'],
            'accounting_period' => $result['accounting_period'],
            'branch' => $result['branch'],
            'warehouse' => $result['warehouse'],
        ], 201);
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

    private function draftVerification(array $draft): array
    {
        $gstin = strtoupper((string) ($draft['gstin'] ?? ''));
        $stateCode = (string) ($draft['state_code'] ?? '');
        $warnings = [];
        $checks = [
            ['label' => 'GSTIN format', 'status' => preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin) ? 'passed' : 'blocked'],
            ['label' => 'GSTIN state code', 'status' => substr($gstin, 0, 2) === $stateCode ? 'passed' : 'blocked'],
            ['label' => 'Duplicate company GSTIN', 'status' => Schema::hasTable('companies') && DB::table('companies')->whereRaw('UPPER(gstin) = ?', [$gstin])->exists() ? 'blocked' : 'passed'],
            ['label' => 'Legal name source document', 'status' => 'pending'],
        ];

        if ($checks[3]['status'] === 'pending') {
            $warnings[] = 'Legal company name must be verified from a source document before final creation.';
        }

        return [
            'checks' => $checks,
            'warnings' => $warnings,
            'can_create' => false,
            'create_policy' => 'PHOOLTAS will not be created until final details are explicitly approved.',
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
