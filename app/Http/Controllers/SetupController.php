<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class SetupController extends Controller
{
    public function branches()
    {
        return ModuleController::render('branches', 'Branches');
    }

    public function employees()
    {
        if ($redirect = AppController::guardPage('employees')) return $redirect;
        return Inertia::render('Payroll/Index', ['page' => 'employees', 'title' => 'Employees']);
    }

    public function users()
    {
        return ModuleController::render('users', 'Users & Roles');
    }

    public function saas()
    {
        return ModuleController::render('saas', 'SaaS Admin');
    }

    public function settings()
    {
        return ModuleController::render('settings', 'Settings');
    }
}
