<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Mfa;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class MfaController extends Controller
{
    /** The user completing sign-in, or the signed-in, verified user re-enrolling from their profile. */
    private function subject()
    {
        if ($u = Mfa::pendingUser()) {
            return [$u, true];
        }
        $u = Auth::user();

        return $u && Mfa::isVerified($u) ? [$u, false] : [null, false];
    }

    public function challenge()
    {
        $user = Mfa::pendingUser();
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Your sign-in expired. Please sign in again.']);
        }
        if (! $user->hasMfa()) {
            return redirect()->route('mfa.setup');
        }

        return view('mfa.challenge', ['user' => $user]);
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|string|max:32']);
        $user = Mfa::pendingUser();
        if (! $user || ! $user->hasMfa()) {
            return redirect()->route('login')->withErrors(['email' => 'Your sign-in expired. Please sign in again.']);
        }

        $code = trim($request->input('code'));
        $usedRecovery = false;
        $ok = preg_match('/^\d{6}$/', preg_replace('/\s/', '', $code))
            ? $user->verifyTotp($code)
            : ($usedRecovery = $user->useRecoveryCode($code));

        if (! $ok) {
            ActivityLog::record('mfa', null, 'failure', "Failed MFA code for {$user->email}");
            if (! Mfa::failedAttempt()) {
                return redirect()->route('login')->withErrors(['email' => 'Too many incorrect codes. Sign in again.']);
            }

            return back()->withErrors(['code' => 'That code is not valid. Check that your device time is correct and use the newest code.']);
        }

        Mfa::complete($request, $user, $usedRecovery ? 'recovery code' : 'authenticator');
        if ($usedRecovery) {
            return redirect()->route('profile.show')->with('status', 'Signed in with a recovery code. '.$user->remainingRecoveryCodes().' codes left. If you lost your authenticator, set up a new one now.');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function setup(Request $request)
    {
        [$user, $signingIn] = $this->subject();
        if (! $user) {
            return redirect()->route('login');
        }
        if ($signingIn && $user->hasMfa()) {
            return redirect()->route('mfa.challenge');
        }

        $secret = $request->session()->get(Mfa::SETUP_SECRET) ?? Totp::generateSecret();
        $request->session()->put(Mfa::SETUP_SECRET, $secret);
        $issuer = Setting::branding()['company_name'] ?: 'TenantsGuard';
        $uri = Totp::uri($secret, $user->email, $issuer);

        return view('mfa.setup', [
            'user' => $user,
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'qr' => Totp::qrSvg($uri),
            'signingIn' => $signingIn,
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|string|max:16', 'device_name' => 'nullable|string|max:80']);
        [$user, $signingIn] = $this->subject();
        $secret = $request->session()->get(Mfa::SETUP_SECRET);
        if (! $user || ! $secret) {
            return redirect()->route('login')->withErrors(['email' => 'Your session expired. Please sign in again.']);
        }

        $step = Totp::verify($secret, $request->input('code'));
        if ($step === null) {
            if ($signingIn && ! Mfa::failedAttempt()) {
                return redirect()->route('login')->withErrors(['email' => 'Too many incorrect codes. Sign in again.']);
            }

            return back()->withErrors(['code' => 'That code does not match. Scan the QR code again and enter the current 6-digit code.']);
        }

        $codes = $user->enableMfa($secret, $step, $request->input('device_name'));
        $request->session()->forget(Mfa::SETUP_SECRET);
        ActivityLog::record('mfa', null, 'success', "Authenticator registered for {$user->email}");

        if ($signingIn) {
            Mfa::complete($request, $user, 'new authenticator');
        }
        $request->session()->flash('recovery_codes', $codes);

        return redirect()->route('mfa.recovery');
    }

    public function recovery(Request $request)
    {
        $codes = $request->session()->get('recovery_codes');
        if (! $codes) {
            return redirect()->route('profile.show');
        }

        return view('mfa.recovery', ['codes' => $codes]);
    }

    public function cancel(Request $request)
    {
        $request->session()->forget([Mfa::PENDING, Mfa::SETUP_SECRET]);

        return redirect()->route('login');
    }

    // ------------------------------------------------------------------ signed-in user self-service

    public function regenerate(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $user = $request->user();
        if (! $user->verifyTotp($request->input('code'))) {
            return back()->withErrors(['mfa_code' => 'Authenticator code is not valid.']);
        }
        $request->session()->flash('recovery_codes', $user->regenerateRecoveryCodes());
        ActivityLog::record('mfa', null, 'info', 'Regenerated recovery codes');

        return redirect()->route('mfa.recovery');
    }

    /**
     * Lost phone: remove the registered device without an authenticator code.
     * Confirmed with the account password or an unused recovery code. Other sessions are signed out.
     */
    public function removeOwn(Request $request)
    {
        $request->validate(['confirmation' => 'required|string|max:255']);
        $user = $request->user();
        $proof = $request->input('confirmation');

        $ok = Hash::check($proof, $user->password) || $user->useRecoveryCode($proof);
        if (! $ok) {
            ActivityLog::record('mfa', null, 'failure', 'Failed attempt to remove own MFA device');

            return back()->withErrors(['remove_confirmation' => 'Password or recovery code is not correct.']);
        }

        $device = $user->mfa_device_name ?: 'authenticator';
        $user->resetMfa();
        $user->endAllSessions($request->session()->getId());
        $request->session()->forget(Mfa::SETUP_SECRET);
        ActivityLog::record('mfa.reset', null, 'info', "Removed own MFA device \"{$device}\" (lost or replaced phone)");

        return redirect()->route('mfa.setup')->with('status', "\"{$device}\" was removed. Register your new device now. If you leave this page, you will be asked at your next sign-in.");
    }

    public function replace(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $user = $request->user();
        if (! $user->verifyTotp($request->input('code'))) {
            return back()->withErrors(['mfa_code' => 'Authenticator code is not valid.']);
        }
        $request->session()->forget(Mfa::SETUP_SECRET);
        ActivityLog::record('mfa', null, 'info', 'Started replacing authenticator');

        // The old authenticator keeps working until the new one is confirmed.
        return redirect()->route('mfa.setup');
    }
}
