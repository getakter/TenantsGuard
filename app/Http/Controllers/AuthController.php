<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Mfa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required']);

        // First factor only: the session is not signed in until the authenticator code is verified.
        if (! Auth::validate($credentials)) {
            ActivityLog::record('login', null, 'failure', 'Failed password sign-in for '.strtolower($credentials['email']));

            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
        }
        $user = User::whereRaw('LOWER(email) = ?', [strtolower($credentials['email'])])->first();
        if (! $user->is_active || ! $user->role_id) {
            return back()->withErrors(['email' => 'Your account is disabled or has no role. Contact a Global Administrator.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect(Mfa::begin($user, $request->boolean('remember'), 'password'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
