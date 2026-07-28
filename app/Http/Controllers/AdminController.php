<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class AdminController extends Controller
{
    public function workspace()
    {
        return Inertia::render('Setup/Workspace', ['page' => 'admin-workspace', 'title' => 'Admin Workspace', 'initial_tab' => 'admin']);
    }

    public function onboarding()
    {
        return Inertia::render('Setup/Workspace', ['page' => 'onboarding', 'title' => 'Onboarding', 'initial_tab' => 'onboarding']);
    }
}
