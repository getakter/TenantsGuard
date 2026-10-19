<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Access;
use App\Support\MicrosoftApp;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index', [
            'branding' => Setting::branding(),
            'ms' => [
                'configured' => MicrosoftApp::configured(),
                'client_id' => MicrosoftApp::clientId(),
                'source' => MicrosoftApp::source(),
                'home_tenant' => MicrosoftApp::homeTenant(),
                'signin_tenants' => MicrosoftApp::signinTenants(),
                'redirects' => MicrosoftApp::redirectUris(),
                'object_id' => Setting::find('ms_app_object_id')?->value,
            ],
            'plan' => Access::appPlan(),
            'notify' => Notifier::config(),
            'health' => $this->health(),
            'interval' => config('tenantsguard.scan_interval_hours'),
            'retention' => config('tenantsguard.retention_days'),
        ]);
    }

    private function health(): array
    {
        $beat = Setting::find('scheduler_last_run')?->value;
        $secret = Setting::find('ms_secret_expires')?->value;
        $check = fn (?bool $ok, string $label, string $detail) => compact('ok', 'label', 'detail');

        return [
            $check(MicrosoftApp::configured(), 'Microsoft app', MicrosoftApp::configured() ? 'Configured ('.MicrosoftApp::clientId().')' : 'Not set up. Clients cannot be connected.'),
            $check($secret ? Carbon::parse($secret)->gt(now()->addDays(30)) : null, 'App secret expiry', $secret ? 'Expires '.Carbon::parse($secret)->toFormattedDateString().' ('.Carbon::parse($secret)->diffForHumans().')' : 'Unknown. Enter the expiry date when saving a manual registration.'),
            $check($beat ? Carbon::parse($beat)->gt(now()->subHours(3)) : false, 'Scheduler (automatic rescans)', $beat ? 'Last run '.Carbon::parse($beat)->diffForHumans() : 'Never ran. Run "php artisan schedule:work" or a Windows Scheduled Task with "php artisan schedule:run" every minute.'),
            $check(filled(Notifier::config()['url']) ?: null, 'Alert notifications', filled(Notifier::config()['url']) ? 'Webhook configured' : 'Not configured (optional)'),
            $check(User::whereNull('mfa_enabled_at')->where('is_active', true)->doesntExist() ?: null, 'MFA enrolment', User::whereNull('mfa_enabled_at')->where('is_active', true)->count().' active user(s) have not registered MFA yet'),
            $check(Tenant::where('consent_status', '!=', 'granted')->where('mode', 'live')->doesntExist() ?: null, 'Client connections', Tenant::where('consent_status', '!=', 'granted')->where('mode', 'live')->count().' client(s) waiting for or failing consent'),
        ];
    }

    public function notifications(Request $request)
    {
        $data = $request->validate([
            'notify_webhook_url' => 'nullable|url|max:1000|starts_with:https://',
            'notify_format' => 'required|in:'.implode(',', array_keys(Notifier::FORMATS)),
            'notify_events' => 'array',
            'notify_events.*' => 'in:'.implode(',', array_keys(Notifier::EVENTS)),
        ]);
        Setting::put('notify_webhook_url', $data['notify_webhook_url'] ?? null);
        Setting::put('notify_format', $data['notify_format']);
        Setting::put('notify_events', json_encode(array_values($data['notify_events'] ?? [])));
        ActivityLog::record('settings', null, 'info', 'Alert notifications updated');

        if ($request->boolean('test')) {
            $ok = Notifier::send('test', 'Test notification', 'TenantsGuard alerts are working. You will be notified about: '.implode(', ', $data['notify_events'] ?? []).'.', null, true);

            return back()->with($ok ? 'status' : 'errors', $ok ? 'Saved. Test message sent.' : (new ViewErrorBag)->put('default', new MessageBag(['notify' => 'Saved, but the webhook did not accept the test message. Check the URL and format.'])));
        }

        return back()->with('status', 'Alert notifications saved.');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:120',
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_url' => 'nullable|url|max:500',
            'report_footer' => 'nullable|string|max:300',
        ]);
        foreach ($data as $k => $v) {
            Setting::put($k, $v);
        }
        ActivityLog::record('settings', null, 'info', 'Branding updated');

        return back()->with('status', 'Settings saved.');
    }
}
