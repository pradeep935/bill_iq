<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AppController;
use App\Http\Controllers\Controller;
use App\Services\Onboarding\BusinessOnboardingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function index(Request $request, BusinessOnboardingService $onboarding)
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
