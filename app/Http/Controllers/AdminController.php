<?php

namespace App\Http\Controllers;

class AdminController extends Controller
{
    public function workspace()
    {
        return ModuleController::render('admin-workspace', 'Admin Workspace');
    }

    public function onboarding()
    {
        return ModuleController::render('onboarding', 'Onboarding');
    }
}
