<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\ConsentService;
use App\Services\Microsoft\AppProvisioner;
use App\Support\MicrosoftApp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Settings > Microsoft app: automatic setup (device code sign-in), manual entry, sign-in tenants. */
class MicrosoftSetupController extends Controller
{
    public function start(Request $request)
    {
        $data = $request->validate(['include_write' => 'boolean', 'mode' => 'nullable|in:create,update']);
        if (($data['mode'] ?? 'create') === 'update' && ! MicrosoftApp::configured()) {
            return response()->json(['status' => 'error', 'message' => 'There is no app registration to update yet.'], 422);
        }
        try {
            $dc = AppProvisioner::startDeviceCode();
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
        $request->session()->put('ms_setup', [
            'device_code' => $dc['device_code'],
            'interval' => $dc['interval'],
            'expires_at' => now()->addSeconds($dc['expires_in'])->timestamp,
            'include_write' => (bool) ($data['include_write'] ?? true),
            'mode' => $data['mode'] ?? 'create',
        ]);

        return response()->json([
            'status' => 'started',
            'user_code' => $dc['user_code'],
            'verification_uri' => $dc['verification_uri'],
            'interval' => $dc['interval'],
            'expires_in' => $dc['expires_in'],
        ]);
    }

    public function poll(Request $request)
    {
        $setup = $request->session()->get('ms_setup');
        if (! $setup) {
            return response()->json(['status' => 'error', 'message' => 'No setup in progress. Start again.'], 422);
        }
        if (now()->timestamp > $setup['expires_at']) {
            $request->session()->forget('ms_setup');

            return response()->json(['status' => 'error', 'message' => 'The sign-in code expired. Start again.'], 422);
        }

        try {
            $poll = AppProvisioner::pollDeviceCode($setup['device_code']);
            if ($poll['status'] !== 'done') {
                return response()->json(['status' => 'pending', 'interval' => $poll['status'] === 'slow_down' ? $setup['interval'] + 5 : $setup['interval']]);
            }
            $request->session()->forget('ms_setup');
            if (($setup['mode'] ?? 'create') === 'update') {
                $count = AppProvisioner::updatePermissions($poll['token'], MicrosoftApp::clientId(), $setup['include_write']);
                ActivityLog::record('setup', null, 'success', "Updated app registration permissions ({$count} permissions)");
                session()->flash('status', "App permissions updated ({$count}). Now re-grant admin consent on each connected tenant (tenant page > Re-grant consent).");

                return response()->json(['status' => 'done', 'client_id' => MicrosoftApp::clientId()]);
            }
            $result = AppProvisioner::provision($poll['token'], $setup['include_write']);
        } catch (Throwable $e) {
            $request->session()->forget('ms_setup');
            ActivityLog::record('setup', null, 'failure', 'Automatic app registration failed: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        ActivityLog::record('setup', null, 'success', "Created Entra app registration {$result['client_id']} with {$result['permissions']} permissions");
        session()->flash('status', 'The TenantsGuard app was created in your Microsoft tenant. You can now connect tenants and sign in with Microsoft.');

        return response()->json(['status' => 'done', 'client_id' => $result['client_id']]);
    }

    public function manual(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|uuid',
            'client_secret' => 'required|string|min:10|max:255',
            'secret_expires' => 'nullable|date|after:today',
            'home_tenant' => 'nullable|string|max:255',
        ]);
        $home = $data['home_tenant'] ?? null;
        if ($home && ! Str::isUuid($home)) {
            try {
                $home = ConsentService::resolveTenantId($home);
            } catch (RuntimeException $e) {
                return back()->withErrors(['home_tenant' => $e->getMessage()])->withInput();
            }
        }
        MicrosoftApp::save($data['client_id'], $data['client_secret'], $home, 'settings');
        Setting::put('ms_secret_expires', $data['secret_expires'] ?? null);
        ActivityLog::record('setup', null, 'info', "Saved existing Entra app registration {$data['client_id']}");

        return back()->with('status', 'App registration saved. Make sure both redirect URIs shown below are added to the app.');
    }

    public function forget()
    {
        MicrosoftApp::forget();
        ActivityLog::record('setup', null, 'info', 'Removed saved Microsoft app credentials');

        return back()->with('status', 'Saved app credentials removed. Values in .env (if any) are used again. The app registration itself still exists in Entra.');
    }

    public function signin(Request $request)
    {
        $data = $request->validate(['signin_tenants' => 'nullable|string|max:2000', 'default_role' => 'nullable|exists:roles,key']);
        $ids = [];
        foreach (array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($data['signin_tenants'] ?? '')))) as $value) {
            try {
                $ids[] = ConsentService::resolveTenantId($value);
            } catch (RuntimeException $e) {
                return back()->withErrors(['signin_tenants' => $e->getMessage()])->withInput();
            }
        }
        Setting::put('ms_signin_tenants', implode(',', array_unique($ids)));
        Setting::put('ms_default_role', $data['default_role'] ?? 'read_only');

        return back()->with('status', 'Microsoft sign-in tenants saved.');
    }
}
