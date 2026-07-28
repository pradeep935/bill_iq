<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class StaffController extends Controller
{
    public function workspace()
    {
        return Inertia::render('Setup/Workspace', ['page' => 'staff-workspace', 'title' => 'Staff Workspace', 'initial_tab' => 'staff']);
    }
}
