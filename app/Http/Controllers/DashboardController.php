<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\BusinessDashboardService;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(BusinessDashboardService $dashboard)
    {
        if ($redirect = AppController::guardPage('dashboard')) {
            return $redirect;
        }

        return Inertia::render('Dashboard', array_merge([
            'page' => 'dashboard',
            'title' => 'Business Dashboard',
            'role_id' => AppController::roleId(),
        ], $dashboard->data()));
    }
}
