<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;
use App\Http\Controllers\AppController;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        if ($version = config('app.asset_version')) {
            return (string) $version;
        }

        $manifest = public_path('build/manifest.json');

        if (is_file($manifest)) {
            return md5_file($manifest) ?: (string) filemtime($manifest);
        }

        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = Auth::user();
        $context = $user ? AppController::context() : null;

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
                'base_url' => $request->getBaseUrl(),
                'financial_year' => $context['financial_year']['name'] ?? '2026-27',
            ],
            'context' => $context,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'failure' => fn () => $request->session()->get('failure'),
            ],
        ]);
    }
}
