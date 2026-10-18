<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Baseline;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Mfa;
use App\Services\Scanner;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->admin = User::first();
        $this->a = DemoTenant::create('Tenant A');
        $this->b = DemoTenant::create('Tenant B');
        app(Scanner::class)->scan($this->a);
        app(Scanner::class)->scan($this->b);
    }

    private function staff(string $role, ?array $tenants = null, array $extra = []): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'@msp.test',
            'password' => 'Password123',
            'role_id' => Role::byKey($role)->id,
            'all_tenants' => $tenants === null,
            'is_active' => true,
        ] + $extra);
        $user->tenants()->sync($tenants ?? []);

        return $user;
    }

    public function test_technician_only_sees_and_works_on_assigned_tenants(): void
    {
        $tech = $this->staff('technician', [$this->a->id]);
        $this->actingAs($tech);

        $this->get(route('dashboard'))->assertOk()->assertSee('Tenant A')->assertDontSee('Tenant B');
        $this->get(route('tenants.show', $this->a))->assertOk();
        $this->get(route('tenants.show', $this->b))->assertForbidden();
        $this->get(route('reports.show', $this->b))->assertForbidden();

        $this->post(route('tenants.scan', $this->a))->assertSessionHas('status');
        $this->post(route('tenants.scan', $this->b))->assertForbidden();
        $this->post(route('fix.store', [$this->a, 'APP-01']))->assertSessionHas('status');
        $this->post(route('fix.store', [$this->b, 'APP-01']))->assertForbidden();

        // Baseline selection for an unassigned tenant is ignored.
        $baseline = Baseline::where('name', 'App consent lockdown')->first();
        $this->post(route('baselines.apply', $baseline), ['selection' => [$this->a->id => ['APP-04'], $this->b->id => ['APP-04']]]);
        $this->assertFalse($this->a->fresh()->demo_state['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateTenants']);
        $this->assertTrue($this->b->fresh()->demo_state['authorizationPolicy']['defaultUserRolePermissions']['allowedToCreateTenants']);

        // No tenant management, settings or user administration.
        $this->get(route('tenants.create'))->assertForbidden();
        $this->post(route('tenants.demo'))->assertForbidden();
        $this->delete(route('tenants.destroy', $this->a))->assertForbidden();
        $this->get(route('settings.index'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('roles.index'))->assertForbidden();

        // Activity only shows assigned tenants.
        ActivityLog::record('scan', $this->b, 'info', 'secret B activity');
        $this->get(route('activity.index'))->assertOk()->assertDontSee('secret B activity');
    }

    public function test_technician_can_create_report_only_policy_but_not_enforce_it(): void
    {
        $this->actingAs($this->staff('technician'));

        $this->post(route('fix.store', [$this->a, 'ID-02']))->assertSessionHas('status');
        $this->post(route('fix.store', [$this->a, 'ID-02']))->assertSessionHasErrors('fix');
        $state = collect($this->a->fresh()->demo_state['conditionalAccess'])->firstWhere('displayName', 'TenantsGuard: Require MFA for all users')['state'];
        $this->assertSame('enabledForReportingButNotEnforced', $state);
        $this->get(route('tenants.show', $this->a))->assertSee('cannot enforce Conditional Access');

        $this->actingAs($this->staff('administrator'));
        $this->post(route('fix.store', [$this->a, 'ID-02']))->assertSessionHas('status');
        $this->assertSame('pass', $this->a->scans()->first()->results()->where('check_id', 'ID-02')->value('status'));
    }

    public function test_read_only_can_view_and_report_but_change_nothing(): void
    {
        $this->actingAs($this->staff('read_only'));

        $this->get(route('tenants.show', $this->a))->assertOk()->assertDontSee('>Scan now<', false)->assertDontSee('Record a risk decision');
        $this->get(route('reports.show', $this->a))->assertOk();
        $this->get(route('reports.excel', $this->a))->assertOk();
        $this->get(route('baselines.index'))->assertOk()->assertDontSee('cell-toggle')->assertSee('Read-only view');

        $this->post(route('tenants.scan', $this->a))->assertForbidden();
        $this->post(route('fix.store', [$this->a, 'APP-01']))->assertForbidden();
        $this->post(route('annotations.store', [$this->a, 'ID-10']), ['type' => 'note', 'note' => 'x'])->assertForbidden();
        $this->post(route('baselines.apply', Baseline::first()), ['selection' => [$this->a->id => ['APP-01']]])->assertForbidden();
        $this->post(route('baselines.store'), ['name' => 'x', 'check_ids' => ['APP-01']])->assertForbidden();
    }

    public function test_global_admin_manages_users_and_disabled_users_are_locked_out(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('users.index'))->assertOk()->assertSee('Global Administrator');

        $this->post(route('users.store'), [
            'name' => 'Rafi Tech', 'email' => 'rafi@msp.test', 'password' => 'Password123',
            'role_id' => Role::byKey('technician')->id, 'access' => 'selected', 'tenant_ids' => [$this->a->id], 'is_active' => 1,
        ])->assertRedirect(route('users.index'));

        $rafi = User::where('email', 'rafi@msp.test')->first();
        $this->assertSame('technician', $rafi->role->key);
        $this->assertFalse($rafi->all_tenants);
        $this->assertSame([$this->a->id], $rafi->tenants()->pluck('tenants.id')->all());

        // Selected access needs at least one tenant.
        $this->post(route('users.store'), [
            'name' => 'X', 'email' => 'x@msp.test', 'password' => 'Password123',
            'role_id' => Role::byKey('technician')->id, 'access' => 'selected', 'is_active' => 1,
        ])->assertSessionHasErrors('tenant_ids');

        auth()->logout();
        $this->post('/login', ['email' => 'rafi@msp.test', 'password' => 'Password123'])->assertRedirect(route('mfa.setup'));
        $this->get(route('mfa.setup'))->assertOk();
        $secret = session(Mfa::SETUP_SECRET);
        $this->post(route('mfa.confirm'), ['code' => Totp::code($secret, Totp::currentStep())])->assertRedirect(route('mfa.recovery'));
        $this->assertAuthenticatedAs($rafi);
        $this->assertNotNull($rafi->fresh()->last_login_at);

        // Deactivate: the live session ends and new sign-ins are refused.
        $rafi->update(['is_active' => false]);
        $this->app['auth']->forgetGuards(); // a real request reloads the user from the database
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => 'rafi@msp.test', 'password' => 'Password123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_last_global_admin_is_protected(): void
    {
        $this->actingAs($this->admin);
        $base = ['name' => $this->admin->name, 'email' => $this->admin->email, 'access' => 'all', 'is_active' => 1];

        $this->put(route('users.update', $this->admin), $base + ['role_id' => Role::byKey('technician')->id])->assertSessionHasErrors('role_id');
        $this->put(route('users.update', $this->admin), array_merge($base, ['role_id' => Role::byKey('global_admin')->id, 'is_active' => 0]))->assertSessionHasErrors('role_id');
        $this->assertTrue($this->admin->fresh()->isGlobalAdmin());
        $this->assertTrue($this->admin->fresh()->is_active);

        $other = $this->staff('global_admin');
        $this->actingAs($other)->delete(route('users.destroy', $this->admin))->assertRedirect();
        $this->assertNull(User::find($this->admin->id));
        $this->delete(route('users.destroy', $other))->assertSessionHasErrors('user');
    }

    public function test_user_managers_cannot_escalate_privileges(): void
    {
        $role = Role::create(['key' => 'staff_mgr', 'name' => 'Staff manager', 'permissions' => ['tenants.view', 'users.manage'], 'is_system' => false]);
        $mgr = User::create(['name' => 'Mgr', 'email' => 'mgr@msp.test', 'password' => 'Password123', 'role_id' => $role->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($mgr);

        $this->get(route('users.create'))->assertOk()
            ->assertDontSee('name="role_id" value="'.Role::byKey('global_admin')->id.'"', false)
            ->assertDontSee('name="role_id" value="'.Role::byKey('administrator')->id.'"', false);
        $this->post(route('users.store'), [
            'name' => 'Evil', 'email' => 'evil@msp.test', 'password' => 'Password123',
            'role_id' => Role::byKey('global_admin')->id, 'access' => 'all', 'is_active' => 1,
        ])->assertSessionHasErrors('role_id');
        $this->get(route('users.edit', $this->admin))->assertForbidden();
        $this->post(route('roles.store'), ['name' => 'Everything', 'permissions' => ['settings.manage']])->assertForbidden();

        // Allowed: a role within the manager's own permissions.
        $this->post(route('users.store'), [
            'name' => 'Peer', 'email' => 'peer@msp.test', 'password' => 'Password123',
            'role_id' => $role->id, 'access' => 'all', 'is_active' => 1,
        ])->assertRedirect(route('users.index'));
    }

    public function test_global_admin_creates_custom_role_and_view_is_always_granted(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('roles.store'), ['name' => 'Report writer', 'description' => 'Reports only', 'permissions' => ['reports.view']])
            ->assertRedirect(route('roles.index'));

        $role = Role::where('name', 'Report writer')->first();
        $this->assertEqualsCanonicalizing(['tenants.view', 'reports.view'], $role->permissions);
        $this->put(route('roles.update', Role::byKey('global_admin')), ['name' => 'x', 'permissions' => []])->assertForbidden();
        $this->delete(route('roles.destroy', Role::byKey('technician')))->assertSessionHasErrors('role');
        $this->get(route('roles.index'))->assertOk()->assertSee('Report writer')->assertSee('Enforce Conditional Access policies');
    }

    public function test_scoped_administrator_keeps_access_to_tenants_they_add(): void
    {
        $adm = $this->staff('administrator', [$this->a->id]);
        $this->actingAs($adm)->post(route('tenants.demo'))->assertRedirect();

        $new = Tenant::latest('id')->first();
        $this->assertTrue($adm->canAccessTenant($new));
        $this->get(route('tenants.show', $new))->assertOk();
        $this->get(route('tenants.show', $this->b))->assertForbidden();
    }

    public function test_profile_lists_role_and_password_change(): void
    {
        $tech = $this->staff('technician', [$this->a->id]);
        $this->actingAs($tech)->get(route('profile.show'))->assertOk()->assertSee('Technician')->assertSee('Tenant A')->assertDontSee('Manage TenantsGuard users');

        $this->post(route('profile.password'), ['current_password' => 'wrong', 'password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9'])
            ->assertSessionHasErrors('current_password');
        $this->post(route('profile.password'), ['current_password' => 'Password123', 'password' => 'NewPassword9', 'password_confirmation' => 'NewPassword9'])
            ->assertSessionHas('status');
    }
}
