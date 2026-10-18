<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', [
            'users' => User::with('role')->withCount('tenants')->orderBy('name')->get(),
            'tenantCount' => Tenant::count(),
        ]);
    }

    public function create(Request $request)
    {
        return view('users.form', $this->formData($request, new User(['all_tenants' => true, 'is_active' => true])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => $data['password'] ?: Str::random(48),
            'role_id' => $data['role_id'],
            'all_tenants' => $data['all_tenants'],
            'is_active' => $data['is_active'],
        ]);
        $user->tenants()->sync($data['all_tenants'] ? [] : $data['tenant_ids']);
        ActivityLog::record('user.created', null, 'info', "User {$user->email} created as {$user->role->name}".$this->scopeText($user));

        return redirect()->route('users.index')->with('status', "User {$user->name} created.".($data['password'] ? '' : ' They sign in with Microsoft.'));
    }

    public function edit(Request $request, User $user)
    {
        $this->guardTarget($request, $user);

        return view('users.form', $this->formData($request, $user));
    }

    public function update(Request $request, User $user)
    {
        $this->guardTarget($request, $user);
        $data = $this->validated($request, $user);

        $global = Role::byKey(Role::GLOBAL_ADMIN);
        $losesGlobal = $user->role_id === $global->id && ((int) $data['role_id'] !== $global->id || ! $data['is_active']);
        if ($losesGlobal && $this->activeGlobalAdmins() <= 1) {
            throw ValidationException::withMessages(['role_id' => 'This is the last active Global Administrator. Promote someone else first.']);
        }
        if ($user->is($request->user()) && ((int) $data['role_id'] !== $user->role_id || ! $data['is_active'])) {
            throw ValidationException::withMessages(['role_id' => 'You cannot change your own role or deactivate yourself.']);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'role_id' => $data['role_id'],
            'all_tenants' => $data['all_tenants'],
            'is_active' => $data['is_active'],
        ]);
        if ($data['password']) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->tenants()->sync($data['all_tenants'] ? [] : $data['tenant_ids']);
        ActivityLog::record('user.updated', null, 'info', "User {$user->email} updated: {$user->role->name}".$this->scopeText($user).($user->is_active ? '' : ', deactivated'));

        return redirect()->route('users.index')->with('status', "User {$user->name} saved.");
    }

    public function destroy(Request $request, User $user)
    {
        $this->guardTarget($request, $user);
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }
        if ($user->isGlobalAdmin() && $user->is_active && $this->activeGlobalAdmins() <= 1) {
            return back()->withErrors(['user' => 'You cannot delete the last active Global Administrator.']);
        }
        ActivityLog::record('user.deleted', null, 'info', "User {$user->email} deleted");
        $user->delete();

        return redirect()->route('users.index')->with('status', 'User deleted.');
    }

    /** Lost phone: clear the user's authenticator. They enroll a new one at their next sign-in. */
    public function resetMfa(Request $request, User $user)
    {
        $this->guardTarget($request, $user);
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'Use My account to replace your own authenticator.']);
        }
        $device = $user->mfa_device_name ?: 'authenticator';
        $user->resetMfa();
        $ended = $user->endAllSessions();
        ActivityLog::record('mfa.reset', null, 'info', "Removed MFA device \"{$device}\" for {$user->email}, signed out of {$ended} session(s). New device registration required at next sign-in.");

        return back()->with('status', "Removed {$user->name}'s MFA device \"{$device}\" and signed them out everywhere. They must register a new device at their next sign-in.");
    }

    // ------------------------------------------------------------------

    /** Roles the acting user may hand out: Global Admins any; others only roles within their own permissions. */
    private function assignableRoles(User $actor)
    {
        return Role::orderByDesc('is_system')->orderBy('id')->get()->filter(function (Role $role) use ($actor) {
            if ($actor->isGlobalAdmin()) {
                return true;
            }
            if ($role->isGlobalAdmin()) {
                return false;
            }

            return collect($role->permissions)->every(fn ($p) => $actor->hasPermission($p));
        })->values();
    }

    /** Only Global Administrators may edit Global Administrators. */
    private function guardTarget(Request $request, User $target): void
    {
        abort_if($target->isGlobalAdmin() && ! $request->user()->isGlobalAdmin(), 403, 'Only a Global Administrator can change a Global Administrator.');
    }

    private function formData(Request $request, User $user): array
    {
        return [
            'user' => $user,
            'roles' => $this->assignableRoles($request->user()),
            'tenants' => Tenant::orderBy('name')->get(),
            'assigned' => $user->exists ? $user->tenants()->pluck('tenants.id')->all() : [],
        ];
    }

    private function validated(Request $request, ?User $user): array
    {
        $roleIds = $this->assignableRoles($request->user())->pluck('id')->all();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'role_id' => ['required', 'integer', Rule::in($roleIds)],
            'password' => ['nullable', 'string', Password::min(10)->letters()->numbers()],
            'access' => 'required|in:all,selected',
            'tenant_ids' => 'array',
            'tenant_ids.*' => 'integer|exists:tenants,id',
            'is_active' => 'nullable|boolean',
        ], [
            'role_id.in' => 'You cannot assign that role.',
        ]);

        $data['all_tenants'] = $data['access'] === 'all';
        $data['tenant_ids'] = $data['tenant_ids'] ?? [];
        $data['is_active'] = $request->boolean('is_active');
        $data['password'] = $data['password'] ?? null;
        if (! $data['all_tenants'] && ! $data['tenant_ids'] && ! Role::find($data['role_id'])?->isGlobalAdmin()) {
            throw ValidationException::withMessages(['tenant_ids' => 'Select at least one tenant, or choose "All tenants".']);
        }

        return $data;
    }

    private function activeGlobalAdmins(): int
    {
        return User::where('is_active', true)->whereHas('role', fn ($q) => $q->where('key', Role::GLOBAL_ADMIN))->count();
    }

    private function scopeText(User $user): string
    {
        return $user->seesAllTenants() ? ', all tenants' : ', '.$user->tenants()->count().' tenants';
    }
}
