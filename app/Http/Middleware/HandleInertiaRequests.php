<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = Auth::user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_id' => (int) ($user->role_id ?? 2),
                    'is_active' => (bool) $user->is_active,
                ] : null,
            ],
            'app' => [
                'name' => config('app.name', 'Bill IQ'),
                'url' => $request->getSchemeAndHttpHost(),
                'financial_year' => '2026-27',
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'failure' => fn () => $request->session()->get('failure'),
            ],
        ]);
    }
}
