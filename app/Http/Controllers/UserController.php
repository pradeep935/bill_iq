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

        if (
            !$user ||
            $user->status !== 'active' ||
            (int) $user->is_active !== 1 ||
            !Hash::check($credentials['password'], $user->password_hash)
        ) {
            return Redirect::back()
                ->with('failure', 'Invalid email or password')
                ->withInput($request->only('username'));
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'failed_login_attempts' => 0,
        ]);

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

    private function homeForRole(int $roleId): string
    {
        return match ($roleId) {
            1 => '/app/admin/workspace',
            3 => '/app/staff/workspace',
            default => '/app',
        };
    }
}
