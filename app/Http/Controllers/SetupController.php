<?php

namespace App\Http\Controllers;

use App\Services\Admin\AdminWorkspaceService;
use Illuminate\Http\Request;
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
        ], $workspace->build(AppController::businessId(), $request->user(), 'settings')));
    }
}
