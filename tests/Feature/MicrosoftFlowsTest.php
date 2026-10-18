<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mfa;
use App\Support\MicrosoftApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MicrosoftFlowsTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '11111111-aaaa-bbbb-cccc-000000000001';

    private const HOME = '22222222-aaaa-bbbb-cccc-000000000002';

    private const CUSTOMER = '33333333-aaaa-bbbb-cccc-000000000003';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123', 'tenantsguard.microsoft.client_id' => null, 'tenantsguard.microsoft.client_secret' => null]);
        $this->seed();
        $this->user = User::first();
    }

    private static function jwt(array $claims): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');

        return $b64(['alg' => 'none']).'.'.$b64($claims).'.sig';
    }

    private function configureApp(): void
    {
        MicrosoftApp::save(self::CLIENT, 'super-secret-value', self::HOME, 'settings');
    }

    /** Fake Microsoft login + a minimal Graph tenant for app-only calls. */
    private function fakeGraphTenant(string $name = 'Contoso'): void
    {
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/organization*' => Http::response(['value' => [['displayName' => $name, 'verifiedDomains' => [['name' => 'contoso.com', 'isDefault' => true]]]]]),
            'graph.microsoft.com/v1.0/policies/identitySecurityDefaultsEnforcementPolicy' => Http::response(['isEnabled' => true]),
            'graph.microsoft.com/v1.0/policies/authorizationPolicy' => Http::response(['allowInvitesFrom' => 'everyone', 'defaultUserRolePermissions' => ['allowedToCreateApps' => true]]),
            'graph.microsoft.com/*' => Http::response(['value' => []]),
        ]);
    }

    public function test_setup_screens_offer_both_methods_when_not_configured(): void
    {
        $this->assertFalse(MicrosoftApp::configured());
        $this->get('/login')->assertOk()->assertSee('Sign in with Microsoft')->assertSee('aria-disabled="true"', false);

        $this->actingAs($this->user)->get(route('settings.index'))->assertOk()
            ->assertSee('Method A: Automatic setup')->assertSee('Method B: Manual registration')
            ->assertSee('http://localhost:8000/consent/callback')->assertSee('http://localhost:8000/auth/microsoft/callback');
        $this->get(route('tenants.create'))->assertOk()->assertSee('Connect with Microsoft');
        $this->get(route('connect.microsoft'))->assertRedirect(route('settings.index'));
    }

    public function test_manual_registration_is_saved_encrypted(): void
    {
        $this->actingAs($this->user)->post(route('ms-setup.manual'), [
            'client_id' => self::CLIENT, 'client_secret' => 'super-secret-value', 'home_tenant' => self::HOME,
        ])->assertSessionHas('status');

        $this->assertTrue(MicrosoftApp::configured());
        $this->assertSame('super-secret-value', MicrosoftApp::clientSecret());
        $this->assertNotSame('super-secret-value', Setting::find('ms_client_secret')->value);
        $this->assertSame([self::HOME], MicrosoftApp::signinTenants());
        $this->get(route('settings.index'))->assertSee(self::CLIENT)->assertSee('Connected');
    }

    public function test_automatic_setup_creates_the_app_registration(): void
    {
        $userToken = self::jwt(['tid' => self::HOME, 'scp' => 'Application.ReadWrite.All']);
        Http::fake([
            'login.microsoftonline.com/organizations/oauth2/v2.0/devicecode' => Http::response([
                'device_code' => 'DEV', 'user_code' => 'ABCD-1234', 'verification_uri' => 'https://microsoft.com/devicelogin', 'interval' => 1, 'expires_in' => 900,
            ]),
            'login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::sequence()
                ->push(['error' => 'authorization_pending'], 400)
                ->push(['access_token' => $userToken]),
            'graph.microsoft.com/v1.0/servicePrincipals(*' => Http::response([
                'appRoles' => [['id' => 'role-org', 'value' => 'Organization.Read.All'], ['id' => 'role-pol', 'value' => 'Policy.ReadWrite.ConditionalAccess']],
                'oauth2PermissionScopes' => [['id' => 'scope-openid', 'value' => 'openid'], ['id' => 'scope-email', 'value' => 'email']],
            ]),
            'graph.microsoft.com/v1.0/applications' => Http::response(['id' => 'obj-1', 'appId' => self::CLIENT], 201),
            'graph.microsoft.com/v1.0/applications/obj-1/addPassword' => Http::response(['secretText' => 'generated-secret']),
            'graph.microsoft.com/v1.0/servicePrincipals' => Http::response(['id' => 'sp-1'], 201),
        ]);

        $this->actingAs($this->user)->postJson(route('ms-setup.start'), ['include_write' => true])
            ->assertOk()->assertJson(['status' => 'started', 'user_code' => 'ABCD-1234']);
        $this->postJson(route('ms-setup.poll'))->assertOk()->assertJson(['status' => 'pending']);
        $this->postJson(route('ms-setup.poll'))->assertOk()->assertJson(['status' => 'done', 'client_id' => self::CLIENT]);

        $this->assertTrue(MicrosoftApp::configured());
        $this->assertSame('generated-secret', MicrosoftApp::clientSecret());
        $this->assertSame('wizard', MicrosoftApp::source());
        $this->assertSame(self::HOME, MicrosoftApp::homeTenant());

        Http::assertSent(function ($req) {
            if ($req->url() !== 'https://graph.microsoft.com/v1.0/applications' || $req->method() !== 'POST') {
                return false;
            }
            $access = collect($req['requiredResourceAccess'][0]['resourceAccess']);

            return $req['signInAudience'] === 'AzureADMultipleOrgs'
                && in_array('http://localhost:8000/consent/callback', $req['web']['redirectUris'], true)
                && $access->contains(['id' => 'role-pol', 'type' => 'Role'])
                && $access->contains(['id' => 'scope-openid', 'type' => 'Scope']);
        });
    }

    public function test_connect_with_microsoft_popup_creates_and_scans_the_tenant(): void
    {
        $this->configureApp();
        $this->fakeGraphTenant();

        $res = $this->actingAs($this->user)->get(route('connect.microsoft', ['popup' => 1]));
        $res->assertRedirect();
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/organizations/v2.0/adminconsent?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(self::CLIENT, $q['client_id']);
        $this->assertSame('http://localhost:8000/consent/callback', $q['redirect_uri']);

        $done = $this->get(route('consent.callback', ['admin_consent' => 'True', 'tenant' => self::CUSTOMER, 'state' => $q['state']]));
        $done->assertOk()->assertSee('tg-microsoft-done');

        $tenant = Tenant::where('ms_tenant_id', self::CUSTOMER)->first();
        $this->assertNotNull($tenant);
        $this->assertSame('granted', $tenant->consent_status);
        $this->assertSame('Contoso', $tenant->name);
        $this->assertSame('contoso.com', $tenant->domain);
        $this->assertSame(1, $tenant->scans()->count());
        $this->assertSame('pass', $tenant->scans()->first()->results()->where('check_id', 'ID-01')->value('status'));
        $this->assertSame('fail', $tenant->scans()->first()->results()->where('check_id', 'APP-01')->value('status'));
    }

    public function test_consent_link_works_for_customer_admin_without_console_session(): void
    {
        $this->configureApp();
        $this->fakeGraphTenant('Customer Ltd');
        $tenant = Tenant::create(['name' => 'customer.com', 'domain' => 'customer.com', 'ms_tenant_id' => self::CUSTOMER, 'mode' => 'live']);
        $state = Crypt::encryptString(json_encode(['t' => $tenant->id, 'p' => false]));

        $this->get(route('consent.callback', ['admin_consent' => 'True', 'tenant' => self::CUSTOMER, 'state' => $state]))
            ->assertOk()->assertSee('Your organisation is now connected');
        $this->assertSame('granted', $tenant->fresh()->consent_status);

        $this->get(route('consent.callback', ['error' => 'access_denied', 'error_description' => 'The admin canceled', 'state' => $state]))
            ->assertOk()->assertSee('The admin canceled');
    }

    public function test_consent_for_wrong_tenant_is_rejected(): void
    {
        $this->configureApp();
        $tenant = Tenant::create(['name' => 'x', 'ms_tenant_id' => self::CUSTOMER, 'mode' => 'live']);
        $state = Crypt::encryptString(json_encode(['t' => $tenant->id, 'p' => false]));

        $this->actingAs($this->user)->get(route('consent.callback', ['admin_consent' => 'True', 'tenant' => self::HOME, 'state' => $state]))
            ->assertRedirect(route('tenants.show', $tenant))->assertSessionHasErrors('consent');
        $this->assertSame('pending', $tenant->fresh()->consent_status);
    }

    public function test_sign_in_with_microsoft_creates_user_from_allowed_tenant(): void
    {
        $this->configureApp();

        $res = $this->get(route('auth.microsoft', ['popup' => 1]));
        $res->assertRedirect();
        parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('select_account', $q['prompt']);
        $nonce = session('ms_oidc')['nonce'];

        Http::fake(['login.microsoftonline.com/*' => Http::response(['id_token' => self::jwt([
            'aud' => self::CLIENT, 'nonce' => $nonce, 'oid' => 'oid-megan', 'tid' => self::HOME,
            'preferred_username' => 'megan@contoso.com', 'name' => 'Megan Bowen',
        ])])]);

        $this->get(route('auth.microsoft.callback', ['code' => 'abc', 'state' => $q['state']]))->assertOk()->assertSee('tg-microsoft-done');
        $user = User::where('email', 'megan@contoso.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('oid-megan', $user->microsoft_oid);
        // Microsoft is the first factor only: TenantsGuard MFA must follow.
        $this->assertGuest();
        $this->assertSame($user->id, session(Mfa::PENDING)['id']);
    }

    public function test_sign_in_with_microsoft_rejects_unknown_tenant(): void
    {
        $this->configureApp();
        $res = $this->get(route('auth.microsoft'));
        parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $nonce = session('ms_oidc')['nonce'];

        Http::fake(['login.microsoftonline.com/*' => Http::response(['id_token' => self::jwt([
            'aud' => self::CLIENT, 'nonce' => $nonce, 'oid' => 'oid-x', 'tid' => self::CUSTOMER, 'preferred_username' => 'someone@other.com',
        ])])]);

        $this->get(route('auth.microsoft.callback', ['code' => 'abc', 'state' => $q['state']]))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'someone@other.com')->first());
    }

    public function test_sign_in_rejects_bad_state_and_wrong_audience(): void
    {
        $this->configureApp();
        $this->get(route('auth.microsoft.callback', ['code' => 'abc', 'state' => 'forged']))->assertRedirect(route('login'));

        $res = $this->get(route('auth.microsoft'));
        parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        Http::fake(['login.microsoftonline.com/*' => Http::response(['id_token' => self::jwt([
            'aud' => 'another-app', 'nonce' => session('ms_oidc')['nonce'], 'oid' => 'o', 'tid' => self::HOME, 'preferred_username' => 'a@b.com',
        ])])]);
        $this->get(route('auth.microsoft.callback', ['code' => 'abc', 'state' => $q['state']]))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_requests_on_127_0_0_1_are_sent_to_the_app_url_host(): void
    {
        $this->get('http://127.0.0.1:8000/login')->assertRedirect('http://localhost:8000/login');
    }
}
