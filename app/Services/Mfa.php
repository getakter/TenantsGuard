<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Two-step sign-in. After the first factor (password or Microsoft account), the user is only
 * "pending" in the session. They are signed in only after a valid authenticator code, or after
 * enrolling an authenticator if they do not have one yet. MFA is mandatory for every role.
 */
class Mfa
{
    public const PENDING = 'mfa.pending';

    public const VERIFIED = 'mfa_verified';

    public const SETUP_SECRET = 'mfa.setup_secret';

    public const MAX_ATTEMPTS = 5;

    public const PENDING_MINUTES = 10;

    /** Start the second step. Returns the URL to continue at. */
    public static function begin(User $user, bool $remember, string $method): string
    {
        session()->put(self::PENDING, [
            'id' => $user->id,
            'remember' => $remember,
            'method' => $method,
            'at' => now()->timestamp,
            'attempts' => 0,
        ]);

        return $user->hasMfa() ? route('mfa.challenge') : route('mfa.setup');
    }

    public static function pendingUser(): ?User
    {
        $p = session(self::PENDING);
        if (! $p || now()->timestamp - $p['at'] > self::PENDING_MINUTES * 60) {
            session()->forget(self::PENDING);

            return null;
        }
        $user = User::find($p['id']);

        return $user && $user->is_active && $user->role_id ? $user : null;
    }

    /** Count a failed attempt. Returns false when the limit is reached (the pending sign-in is dropped). */
    public static function failedAttempt(): bool
    {
        $p = session(self::PENDING);
        $p['attempts'] = ($p['attempts'] ?? 0) + 1;
        if ($p['attempts'] >= self::MAX_ATTEMPTS) {
            session()->forget(self::PENDING);

            return false;
        }
        session()->put(self::PENDING, $p);

        return true;
    }

    public static function complete(Request $request, User $user, string $how): void
    {
        $p = session(self::PENDING, []);
        Auth::login($user, (bool) ($p['remember'] ?? false));
        $request->session()->regenerate();
        $request->session()->forget([self::PENDING, self::SETUP_SECRET]);
        $request->session()->put(self::VERIFIED, $user->id);
        $user->forceFill(['last_login_at' => now()])->save();
        ActivityLog::record('login', null, 'info', 'Signed in ('.($p['method'] ?? 'password').' + '.$how.')');
    }

    public static function isVerified(?User $user): bool
    {
        return $user !== null && session(self::VERIFIED) === $user->id;
    }
}
