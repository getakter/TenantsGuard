<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->load('role', 'tenants');

        return view('profile.show', [
            'user' => $user,
            'permissions' => collect(Permissions::all())->filter(fn ($p) => $user->can($p))->map(fn ($p) => Permissions::label($p))->all(),
        ]);
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $request->user()->update(['password' => $data['password']]);
        ActivityLog::record('password', null, 'info', 'Changed own password');

        return back()->with('status', 'Password changed.');
    }
}
