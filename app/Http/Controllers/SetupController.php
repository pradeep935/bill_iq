<?php

namespace App\Http\Controllers;

class SetupController extends Controller
{
    public function branches()
    {
        return ModuleController::render('branches', 'Branches');
    }

    public function employees()
    {
        return ModuleController::render('employees', 'Employees');
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
