<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mfa;
use App\Services\Microsoft\AppProvisioner;
use App\Support\MicrosoftApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

/**
 * "Sign in with Microsoft" for the TenantsGuard console (OpenID Connect, authorization code flow).
 *
 * Who may sign in:
 *  - an existing console user whose email matches the Microsoft account, or who signed in with it before;
 *  - any user of an allowed tenant (Settings > Microsoft sign-in), who gets an account automatically.
 */
class MicrosoftAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $popup = $request->boolean('popup');
        if (! MicrosoftApp::configured()) {
            return $this->finish($popup, route('login'), 'Microsoft sign-in is not set up yet. Sign in with email and password, then open Settings to connect your Microsoft app.');
        }

        $state = Str::random(40);
        $nonce = Str::random(40);
        $request->session()->put('ms_oidc', ['state' => $state, 'nonce' => $nonce, 'popup' => $popup]);

        return redirect()->away(config('tenantsguard.microsoft.login_base').'/organizations/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => MicrosoftApp::clientId(),
            'response_type' => 'code',
            'redirect_uri' => MicrosoftApp::signinRedirect(),
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request)
    {
        $expected = $request->session()->pull('ms_oidc');
        $popup = (bool) ($expected['popup'] ?? false);

        if (! $expected || ! hash_equals($expected['state'], (string) $request->query('state'))) {
            return $this->finish($popup, route('login'), 'The sign-in request expired. Please try again.');
        }
        if ($request->filled('error')) {
            return $this->finish($popup, route('login'), 'Microsoft sign-in failed: '.strtok((string) ($request->query('error_description') ?: $request->query('error')), "\r\n"));
        }

        try {
            $res = Http::asForm()->timeout(20)->post(config('tenantsguard.microsoft.login_base').'/organizations/oauth2/v2.0/token', [
                'client_id' => MicrosoftApp::clientId(),
                'client_secret' => MicrosoftApp::clientSecret(),
                'grant_type' => 'authorization_code',
                'code' => (string) $request->query('code'),
                'redirect_uri' => MicrosoftApp::signinRedirect(),
                'scope' => 'openid profile email',
            ]);
        } catch (ConnectionException $e) {
            return $this->finish($popup, route('login'), 'Could not reach Microsoft: '.$e->getMessage());
        }
        if (! $res->successful() || ! $res->json('id_token')) {
            return $this->finish($popup, route('login'), 'Microsoft sign-in failed: '.strtok((string) ($res->json('error_description') ?? 'no ID token returned'), "\r\n"));
        }

        // The ID token comes straight from Microsoft's token endpoint over TLS (OIDC Core 3.1.3.7).
        $claims = AppProvisioner::claims($res->json('id_token'));
        if (($claims['aud'] ?? null) !== MicrosoftApp::clientId() || ! hash_equals($expected['nonce'], (string) ($claims['nonce'] ?? ''))) {
            return $this->finish($popup, route('login'), 'The Microsoft ID token could not be validated.');
        }

        $oid = $claims['oid'] ?? null;
        $tid = strtolower((string) ($claims['tid'] ?? ''));
        $email = strtolower((string) ($claims['email'] ?? $claims['preferred_username'] ?? ''));
        $name = (string) ($claims['name'] ?? $email);

        $user = User::where('microsoft_oid', $oid)->first()
            ?? ($email ? User::whereRaw('LOWER(email) = ?', [$email])->first() : null);

        if (! $user && in_array($tid, MicrosoftApp::signinTenants(), true)) {
            $role = Role::byKey(Setting::find('ms_default_role')?->value ?: 'read_only') ?? Role::byKey('read_only');
            $user = User::create([
                'name' => $name,
                'email' => $email ?: $oid.'@microsoft.local',
                'password' => Str::random(48),
                'role_id' => $role?->id,
                'all_tenants' => true,
                'is_active' => true,
            ]);
            ActivityLog::record('user.created', null, 'info', "Console account created for {$email} via Microsoft sign-in ({$role?->name})");
        }
        if (! $user) {
            return $this->finish($popup, route('login'), "{$email} is not allowed to use this console. Ask a Global Administrator to add you under Users.");
        }
        if (! $user->is_active || ! $user->role_id) {
            return $this->finish($popup, route('login'), 'Your account is disabled or has no role. Contact a Global Administrator.');
        }

        $user->forceFill(['microsoft_oid' => $oid, 'microsoft_tid' => $tid])->save();
        $request->session()->regenerate();

        // Microsoft account is the first factor. TenantsGuard MFA is still required.
        return $this->finish($popup, Mfa::begin($user, true, 'Microsoft account'));
    }

    private function finish(bool $popup, string $to, ?string $error = null)
    {
        if ($error) {
            session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => $error])));
        }

        return $popup ? response()->view('auth.popup-done', ['redirect' => $to]) : redirect($to);
    }
}
