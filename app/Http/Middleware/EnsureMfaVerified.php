<?php

namespace App\Http\Middleware;

use App\Services\Mfa;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every authenticated request must come from a session that passed MFA.
 * Sessions that did not (for example, created before MFA was introduced, or a "remember me"
 * cookie) are sent through the second step again.
 */
class EnsureMfaVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! Mfa::isVerified($user)) {
            Auth::logout();
            $request->session()->regenerate();
            $to = Mfa::begin($user, false, 'existing session');

            return $request->expectsJson()
                ? response()->json(['status' => 'error', 'message' => 'Multi-factor authentication required.'], 401)
                : redirect($to);
        }

        // Verified session, but the device was removed (lost phone): register a new one before continuing.
        if ($user && ! $user->hasMfa()) {
            return $request->expectsJson()
                ? response()->json(['status' => 'error', 'message' => 'Register a new MFA device first.'], 401)
                : redirect()->route('mfa.setup');
        }

        return $next($request);
    }
}
