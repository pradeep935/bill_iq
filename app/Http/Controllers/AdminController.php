<?php

namespace App\Http\Controllers;

use App\Services\Admin\AdminWorkspaceService;
use App\Services\Onboarding\BusinessOnboardingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminController extends Controller
{
    public function workspace(Request $request, AdminWorkspaceService $workspace)
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);

        $section = $workspace->normalizeSection($request->query('section', 'admin'), $user);

        return Inertia::render('Setup/Workspace', array_merge([
            'page' => 'admin-workspace',
            'title' => 'Admin Workspace',
            'initial_tab' => $section,
        ], $workspace->build(AppController::businessId(), $user, $section)));
    }

    public function onboarding(Request $request, BusinessOnboardingService $onboarding)
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);

        $filter = $onboarding->normalizeFilter($request->query('filter', 'all'));

        return Inertia::render('Admin/Onboarding/Index', array_merge([
            'page' => 'onboarding',
            'title' => 'Onboarding',
        ], $onboarding->build($user, AppController::businessId(), ['filter' => $filter])));
    }
}
