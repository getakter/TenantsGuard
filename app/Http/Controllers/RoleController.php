<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        return view('roles.index', [
            'roles' => Role::withCount('users')->orderByDesc('is_system')->orderBy('id')->get(),
            'groups' => Permissions::GROUPS,
        ]);
    }

    public function store(Request $request)
    {
        $this->onlyGlobalAdmins($request);
        $data = $this->validated($request);
        $role = Role::create([
            'key' => Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'],
            'is_system' => false,
        ]);
        ActivityLog::record('role.created', null, 'info', "Role \"{$role->name}\" created: ".implode(', ', $role->permissions));

        return redirect()->route('roles.index')->with('status', "Role \"{$role->name}\" created.");
    }

    public function update(Request $request, Role $role)
    {
        $this->onlyGlobalAdmins($request);
        abort_if($role->isGlobalAdmin(), 403, 'The Global Administrator role always has every permission.');
        $data = $this->validated($request, $role);
        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $data['permissions'],
        ]);
        ActivityLog::record('role.updated', null, 'info', "Role \"{$role->name}\" updated: ".implode(', ', $role->permissions));

        return redirect()->route('roles.index')->with('status', "Role \"{$role->name}\" saved.");
    }

    public function destroy(Request $request, Role $role)
    {
        $this->onlyGlobalAdmins($request);
        if ($role->is_system) {
            return back()->withErrors(['role' => 'Built-in roles cannot be deleted.']);
        }
        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'Move the users of this role to another role first.']);
        }
        ActivityLog::record('role.deleted', null, 'info', "Role \"{$role->name}\" deleted");
        $role->delete();

        return redirect()->route('roles.index')->with('status', 'Role deleted.');
    }

    private function onlyGlobalAdmins(Request $request): void
    {
        abort_unless($request->user()->isGlobalAdmin(), 403, 'Only Global Administrators can change roles.');
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => 'nullable|string|max:500',
            'permissions' => 'array',
            'permissions.*' => [Rule::in(Permissions::all())],
        ]);
        // Every role can at least see the tenants it is scoped to.
        $data['permissions'] = array_values(array_unique(array_merge(['tenants.view'], $data['permissions'] ?? [])));

        return $data;
    }
}
