<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoTenant;
use App\Services\Lifecycle\Directory;
use App\Services\Lifecycle\IdentityDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Identity management directory: users, groups and guests. */
class IdentityDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->tenant = DemoTenant::create('Harbour Dental (Demo)');
        (new Directory($this->tenant))->users();
        $this->actingAs(User::first());
    }

    public function test_sidebar_and_picker_lead_to_the_directory(): void
    {
        $this->get(route('operations.index'))->assertSeeInOrder(['Identity Management', 'Users', 'Groups', 'Guests', 'Onboard user']);
        $this->get(route('identity.pick', 'users'))->assertRedirect(route('identity.users', $this->tenant));
        $this->get(route('identity.pick', 'groups'))->assertRedirect(route('identity.groups', $this->tenant));
        $this->get(route('identity.pick', 'guests'))->assertRedirect(route('identity.guests', $this->tenant));
    }

    public function test_users_page_shows_licences_mfa_admins_and_sign_in(): void
    {
        $people = (new IdentityDirectory($this->tenant))->people();
        $this->assertGreaterThan(10, count($people['users']));
        $this->assertCount(2, $people['guests']);
        $avery = collect($people['users'])->firstWhere('name', 'Avery Patel');
        $this->assertTrue($avery['admin']);
        $this->assertContains('Microsoft 365 Business Premium', $avery['licences']);
        $this->assertNotNull(collect($people['users'])->first(fn ($u) => $u['mfa'] === false));
        $this->assertNotNull(collect($people['users'])->first(fn ($u) => $u['stale']));

        $this->get(route('identity.users', $this->tenant))->assertOk()
            ->assertSee('Avery Patel')->assertSee('Enabled without MFA')->assertSee('Stale: no sign-in for 90+ days')->assertSee('Export CSV')->assertSee('Offboard selected')
            ->assertSee(route('operations.user', [$this->tenant, $avery['id']]), false)->assertDontSee('Lab Partner (Guest)');
    }

    public function test_groups_page_and_group_detail(): void
    {
        $groups = (new IdentityDirectory($this->tenant))->groups()['groups'];
        $team = collect($groups)->firstWhere('name', 'Clinic Team');
        $this->assertSame('Team', $team['kind']);
        $this->assertSame('Public', $team['visibility']);
        $this->assertGreaterThan(0, $team['members']);
        $this->assertSame(2, $team['owners']);
        $this->assertSame('Dynamic security', collect($groups)->firstWhere('name', 'All Users (dynamic)')['kind']);

        $this->get(route('identity.groups', $this->tenant))->assertOk()->assertSee('Clinic Team')->assertSee('Without owner')->assertSee('Public');
        $this->get(route('identity.group', [$this->tenant, $team['id']]))->assertOk()->assertSee('Members (')->assertSee('Owners (2)')->assertSee('This group is public');
        $this->get(route('identity.group', [$this->tenant, 'not-a-guid']))->assertNotFound();
    }

    public function test_guests_page_and_guest_removal(): void
    {
        $guests = (new IdentityDirectory($this->tenant))->people()['guests'];
        $pending = collect($guests)->firstWhere('state', 'PendingAcceptance');
        $this->assertSame('contractor.example', $pending['domain']);
        $this->assertTrue(collect($guests)->firstWhere('name', 'Lab Partner (Guest)')['stale']);

        $this->get(route('identity.guests', $this->tenant))->assertOk()->assertSee('Sam Contractor (Guest)')->assertSee('Pending')->assertSee('contractor.example: 1');

        $member = collect($this->tenant->fresh()->demo_state['users'])->firstWhere('userType', 'Member');
        $this->post(route('identity.guests.remove', $this->tenant), ['guests' => [$pending['id']], 'confirm' => 'DELETE 2'])->assertSessionHasErrors('guests');
        $this->post(route('identity.guests.remove', $this->tenant), ['guests' => [$pending['id'], $member['id']], 'confirm' => 'DELETE 2'])->assertSessionHas('status');
        $ids = array_column($this->tenant->fresh()->demo_state['users'], 'id');
        $this->assertNotContains($pending['id'], $ids);
        $this->assertContains($member['id'], $ids, 'members are never deleted through the guest path');
    }

    public function test_permissions_and_tenant_scoping(): void
    {
        $ro = User::create(['name' => 'RO', 'email' => 'ro@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('read_only')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->actingAs($ro);
        $this->get(route('identity.users', $this->tenant))->assertOk()->assertDontSee('id="offb"', false);
        $this->post(route('identity.guests.remove', $this->tenant), ['guests' => ['x'], 'confirm' => 'DELETE 1'])->assertForbidden();

        $scoped = User::create(['name' => 'S', 'email' => 's@msp.test', 'password' => 'Password123', 'role_id' => Role::byKey('technician')->id, 'all_tenants' => false, 'is_active' => true]);
        $this->actingAs($scoped);
        $this->get(route('identity.users', $this->tenant))->assertForbidden();
        $this->get(route('identity.groups', $this->tenant))->assertForbidden();
    }
}
