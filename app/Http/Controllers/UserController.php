<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class UserController extends Controller
{
    public function login()
    {
        if (Auth::check()) {
            return Redirect::to($this->homeForRole((int) (Auth::user()->role_id ?? 2)));
        }

        return view('login.login', ['type' => 'admin']);
    }

    public function postLogin(Request $request)
    {
        $credentials = $request->only('username', 'password');
        $validator = Validator::make($credentials, [
            'username' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!$validator->passes()) {
            return Redirect::back()->withErrors($validator)->withInput();
        }

        $user = User::where('email', trim($credentials['username']))->first();
        $passwordHash = $user?->password_hash ?? $user?->password;

        if (
            !$user ||
            $user->status !== 'active' ||
            (int) $user->is_active !== 1 ||
            !$passwordHash ||
            !Hash::check($credentials['password'], $passwordHash)
        ) {
            return Redirect::back()
                ->with('failure', 'Invalid email or password')
                ->withInput($request->only('username'));
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $loginUpdate = collect([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'failed_login_attempts' => 0,
        ])->only(DB::getSchemaBuilder()->getColumnListing('users'))->all();

        if ($loginUpdate) {
            DB::table('users')->where('id', $user->id)->update($loginUpdate);
        }

        Session::put('access_rights', []);

        return Redirect::intended($this->homeForRole((int) ($user->role_id ?? 2)));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    public function profile()
    {
        return Inertia::render('Profile/Edit', [
            'page' => 'profile',
            'title' => 'Profile',
            'role_id' => (int) (Auth::user()->role_id ?? 2),
        ]);
    }

    private function homeForRole(int $roleId): string
    {
        return match ($roleId) {
            1 => '/app/admin/workspace',
            3 => '/app/staff/workspace',
            default => '/app',
        };
    }
}
