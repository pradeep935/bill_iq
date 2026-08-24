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
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:60'],
            'bank_ifsc' => ['nullable', 'string', 'max:30'],
            'bank_account_holder' => ['nullable', 'string', 'max:255'],
            'invoice_terms' => ['nullable', 'string', 'max:3000'],
            'default_print_format' => ['nullable', 'in:a4,thermal'],
        ]);

        $businessId = AppController::businessId();
        abort_unless(Schema::hasTable('companies') && DB::table('companies')->where('id', $businessId)->exists(), 404);

        $payload = collect($data)
            ->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->map(fn ($value, $key) => $key === 'gstin' && $value ? strtoupper($value) : $value)
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
            'phone' => $value('phone'),
            'email' => $value('email'),
            'bank_name' => $value('bank_name'),
            'bank_account_number' => $value('bank_account_number'),
            'bank_ifsc' => $value('bank_ifsc'),
            'bank_account_holder' => $value('bank_account_holder'),
            'invoice_terms' => $value('invoice_terms'),
            'default_print_format' => $value('default_print_format', 'a4'),
        ];
    }
}
