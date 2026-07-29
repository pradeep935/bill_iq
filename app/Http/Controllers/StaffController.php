<?php

namespace App\Http\Controllers;

use App\Services\Staff\StaffWorkspaceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StaffController extends Controller
{
    public function workspace(Request $request, StaffWorkspaceService $workspace)
    {
        $user = $request->user();

        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin() || $user->isStaffUser()), 403);

        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        return Inertia::render('Staff/Workspace', array_merge([
            'page' => 'staff-workspace',
            'title' => 'Staff Workspace',
        ], $workspace->build($user, AppController::businessId(), $filters)));
    }

    public function tasks(Request $request, StaffWorkspaceService $workspace)
    {
        return $this->workspace($request, $workspace);
    }

    public function attendance(Request $request, StaffWorkspaceService $workspace)
    {
        return $this->workspace($request, $workspace);
    }
}
