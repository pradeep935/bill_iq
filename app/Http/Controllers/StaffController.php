<?php

namespace App\Http\Controllers;

class StaffController extends Controller
{
    public function workspace()
    {
        return ModuleController::render('staff-workspace', 'Staff Workspace');
    }
}
