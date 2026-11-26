<?php

namespace App\Http\Middleware;

use App\Support\DepartmentRole;
use Closure;
use Illuminate\Http\Request;

class RestrictDepartmentRole
{
    public function handle(Request $request, Closure $next)
    {
        $role = (int) ($request->user()?->role_id ?? 0);

        if (!DepartmentRole::is($role) || DepartmentRole::allows($role, $request->method(), $request->path())) {
            return $next($request);
        }

        // Opening a closed screen leads back to their own work; anything else is refused.
        if ($request->isMethod('GET') && (!$request->expectsJson() || $request->header('X-Inertia'))) {
            return redirect(DepartmentRole::home($role));
        }

        return response()->json(['message' => 'Your role (' . DepartmentRole::label($role) . ') does not allow this.'], 403);
    }
}
