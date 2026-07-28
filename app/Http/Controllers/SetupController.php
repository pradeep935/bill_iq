<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class SetupController extends Controller
{
    public function branches()
    {
        if ($redirect = AppController::guardPage('masters')) return $redirect;

        return Inertia::render('Setup/Masters', [
            'page' => 'masters',
            'title' => 'Masters',
            'initial_tab' => 'branch',
        ]);
    }

    public function employees()
    {
        if ($redirect = AppController::guardPage('employees')) return $redirect;
        return Inertia::render('Payroll/Index', ['page' => 'employees', 'title' => 'Employees']);
    }

    public function users()
    {
        if ($redirect = AppController::guardPage('users')) return $redirect;
        return Inertia::render('Setup/Workspace', ['page' => 'users', 'title' => 'Users & Roles', 'initial_tab' => 'users']);
    }

    public function saas()
    {
        if ($redirect = AppController::guardPage('settings')) return $redirect;
        return Inertia::render('Setup/Workspace', ['page' => 'saas', 'title' => 'SaaS Admin', 'initial_tab' => 'saas']);
    }

    public function settings()
    {
        if ($redirect = AppController::guardPage('settings')) return $redirect;
        return Inertia::render('Setup/Workspace', ['page' => 'settings', 'title' => 'Settings', 'initial_tab' => 'settings']);
    }
}
