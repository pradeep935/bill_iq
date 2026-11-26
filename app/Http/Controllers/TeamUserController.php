<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CompanyAccessService;
use App\Support\DepartmentRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Lets a Company Admin (role 2) or Super Admin manage the login users of the CURRENT business:
 * add a user, change role (Admin / Staff / Sales / Purchase), activate / deactivate, reset password.
 * Role 1 (Super Admin) can never be created or edited here.
 */
class TeamUserController extends Controller
{
    private const ROLES = [2, 3, DepartmentRole::SALES, DepartmentRole::PURCHASE];

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || $user->isAdmin()), 403);
        return $user;
    }

    private function memberQuery(int $businessId)
    {
        return User::query()
            ->whereIn('users.id', DB::table('company_user_access')->where('company_id', $businessId)->where('status', 'active')->select('user_id'))
            ->where('users.role_id', '!=', 1);
    }

    private function present(User $u, int $businessId): array
    {
        $access = DB::table('company_user_access')->where('company_id', $businessId)->where('user_id', $u->id)->first();
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role_id' => (int) $u->role_id,
            'role' => (int) $u->role_id === 2 ? 'Admin' : (DepartmentRole::label((int) $u->role_id) ?? 'Staff'),
            'is_active' => (bool) $u->is_active && ($u->status ?? 'active') === 'active',
            'is_owner' => ($access->access_role ?? '') === 'owner',
            'last_login_at' => optional($u->last_login_at ?? null)->toDateTimeString(),
        ];
    }

    public function index(Request $request)
    {
        $this->actor($request);
        $businessId = AppController::businessId();
        return response()->json([
            'users' => $this->memberQuery($businessId)->orderBy('name')->get()->map(fn ($u) => $this->present($u, $businessId))->values(),
            'current_user_id' => $request->user()->id,
        ]);
    }

    public function store(Request $request, CompanyAccessService $access)
    {
        $actor = $this->actor($request);
        $businessId = AppController::businessId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role_id' => ['required', Rule::in(self::ROLES)],
        ], ['email.unique' => 'This email is already used by another user.']);

        $user = DB::transaction(function () use ($data, $businessId, $actor, $access) {
            $user = User::create([
                'tenant_id' => $businessId,
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => Hash::make($data['password']),
                'role_id' => (int) $data['role_id'],
                'status' => 'active',
                'is_active' => true,
            ]);
            $access->grant($user, $businessId, (int) $data['role_id'] === 2 ? 'admin' : 'member', true, $actor->id);
            return $user;
        });

        return response()->json(['message' => 'User created.', 'user' => $this->present($user, $businessId)], 201);
    }

    public function update(Request $request, int $id)
    {
        $actor = $this->actor($request);
        $businessId = AppController::businessId();
        $user = $this->memberQuery($businessId)->whereKey($id)->firstOrFail();
        $isSelf = (int) $user->id === (int) $actor->id;
        $isOwner = DB::table('company_user_access')->where('company_id', $businessId)->where('user_id', $user->id)->where('access_role', 'owner')->exists();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'role_id' => ['sometimes', Rule::in(self::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:100'],
        ]);

        if (($isSelf || $isOwner) && (isset($data['role_id']) && (int) $data['role_id'] !== (int) $user->role_id)) {
            abort(422, 'You cannot change the role of the owner or your own account.');
        }
        if (($isSelf || $isOwner) && array_key_exists('is_active', $data) && !$data['is_active']) {
            abort(422, 'You cannot deactivate the owner or your own account.');
        }

        if (isset($data['name'])) $user->name = $data['name'];
        if (isset($data['role_id'])) $user->role_id = (int) $data['role_id'];
        if (array_key_exists('is_active', $data)) {
            $user->is_active = (bool) $data['is_active'];
            $user->status = $data['is_active'] ? 'active' : 'inactive';
        }
        if (!empty($data['password'])) $user->password = Hash::make($data['password']);
        $user->save();

        if (isset($data['role_id']) && !$isOwner) {
            DB::table('company_user_access')->where('company_id', $businessId)->where('user_id', $user->id)
                ->update(['access_role' => (int) $data['role_id'] === 2 ? 'admin' : 'member', 'updated_at' => now()]);
        }

        return response()->json(['message' => 'User updated.', 'user' => $this->present($user->fresh(), $businessId)]);
    }
}
