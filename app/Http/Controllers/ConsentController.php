<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Services\ConsentService;
use App\Services\Graph\GraphException;
use App\Services\Notifier;
use App\Services\Scanner;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;

class ConsentController extends Controller
{
    /** "Connect with Microsoft": admin consent where Microsoft's account picker decides the tenant. */
    public function start(Request $request)
    {
        $popup = $request->boolean('popup');
        try {
            return redirect()->away(ConsentService::consentUrl(null, $popup));
        } catch (RuntimeException $e) {
            return $this->finish($popup, route('settings.index'), ['consent' => $e->getMessage()]);
        }
    }

    /** Redirect target for https://login.microsoftonline.com/{tenant|organizations}/v2.0/adminconsent */
    public function callback(Request $request, Scanner $scanner)
    {
        try {
            ['tenant' => $tenant, 'popup' => $popup] = ConsentService::decodeState((string) $request->query('state'));
        } catch (DecryptException) {
            return $this->finish(false, route('dashboard'), ['consent' => 'Invalid or expired consent request. Please start again.']);
        }
        $back = $tenant ? route('tenants.show', $tenant) : route('dashboard');

        if ($request->filled('error')) {
            $msg = (string) ($request->query('error_description') ?: $request->query('error'));
            $msg = trim(strtok($msg, "\r\n"));
            if ($tenant) {
                $tenant->update(['consent_status' => 'failed', 'consent_error' => $msg]);
            }
            ActivityLog::record('consent', $tenant, 'failure', 'Admin consent not granted: '.$msg);
            Notifier::send('consent', 'Admin consent failed', $msg, $tenant);

            return $this->finish($popup, $back, ['consent' => 'Admin consent was not granted: '.$msg]);
        }

        $tid = strtolower((string) $request->query('tenant'));
        if (! $tid) {
            return $this->finish($popup, $back, ['consent' => 'Microsoft did not return a tenant ID.']);
        }

        if ($tenant && strcasecmp($tid, (string) $tenant->ms_tenant_id) !== 0) {
            return $this->finish($popup, $back, ['consent' => 'Consent was granted in a different tenant than expected. Sign in with an administrator of '.($tenant->domain ?? $tenant->ms_tenant_id).'.']);
        }

        $isNew = false;
        if (! $tenant) {
            $tenant = Tenant::firstOrCreate(['ms_tenant_id' => $tid, 'mode' => 'live'], ['name' => 'New tenant', 'consent_status' => 'pending']);
            $isNew = $tenant->wasRecentlyCreated;
            $actor = auth()->user();
            if (! $isNew && $actor && ! $actor->canAccessTenant($tenant)) {
                return $this->finish($popup, route('dashboard'), ['consent' => 'This tenant is already managed in TenantsGuard. Ask a Global Administrator to give you access.']);
            }
            $this->assignToCreator($tenant);
        }

        try {
            ConsentService::verify($tenant);
            ActivityLog::record('consent', $tenant, 'success', 'Admin consent granted'.($isNew ? ' (new tenant connected with Microsoft)' : ''));
            $scan = $scanner->scan($tenant, 'onboarding');
        } catch (GraphException $e) {
            return $this->finish($popup, route('tenants.show', $tenant), ['consent' => 'Consent recorded but verification failed: '.$e->friendly().' Wait a minute and click "Verify connection".']);
        }

        return $this->finish($popup, route('tenants.show', $tenant), null, "{$tenant->name} is connected. First scan scored {$scan->score}%.");
    }

    /** Close the popup and navigate the opener, or redirect normally. */
    private function finish(bool $popup, string $to, ?array $errors = null, ?string $status = null)
    {
        // A customer's admin who opened the consent link without a console session.
        if (! auth()->check()) {
            return response()->view('auth.consent-result', [
                'ok' => ! $errors,
                'message' => $errors ? implode(' ', $errors) : 'Thank you. Your organisation is now connected. You can close this window.',
            ]);
        }

        if ($errors) {
            session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag($errors)));
        }
        if ($status) {
            session()->flash('status', $status);
        }

        return $popup ? response()->view('auth.popup-done', ['redirect' => $to]) : redirect($to);
    }
}
