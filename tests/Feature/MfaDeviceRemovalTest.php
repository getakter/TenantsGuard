<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Mfa;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MfaDeviceRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tech;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123', 'session.driver' => 'database']);
        $this->seed();
        $this->admin = User::first();
        $this->tech = User::create(['name' => 'Rafi', 'email' => 'rafi@msp.test', 'password' => 'Password123',
            'role_id' => Role::byKey('technician')->id, 'all_tenants' => true, 'is_active' => true]);
        $this->secret = Totp::generateSecret();
        $this->tech->enableMfa($this->secret, Totp::currentStep() - 5, "Rafi's Pixel 8");
    }

    public function test_device_name_is_recorded_at_enrollment_and_shown_to_admins(): void
    {
        $this->assertSame("Rafi's Pixel 8", $this->tech->fresh()->mfa_device_name);

        $this->actingAs($this->admin);
        $this->get(route('users.index'))->assertOk()->assertSee("Rafi's Pixel 8")->assertSee('Remove MFA device');
        $this->get(route('users.edit', $this->tech))->assertOk()->assertSee('Registered device')->assertSee('lost or stolen phone');
    }

    public function test_admin_removes_lost_device_signs_user_out_and_forces_new_registration(): void
    {
        // The technician has an active session stored in the database.
        DB::table('sessions')->insert(['id' => 'rafi-phone-session', 'user_id' => $this->tech->id, 'ip_address' => '10.0.0.5',
            'user_agent' => 'test', 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        $oldRemember = $this->tech->fresh()->remember_token;

        $this->actingAs($this->admin);
        $this->post(route('users.mfa.reset', $this->tech))->assertSessionHas('status');

        $t = $this->tech->fresh();
        $this->assertFalse($t->hasMfa());
        $this->assertNull($t->mfa_device_name);
        $this->assertSame(0, $t->remainingRecoveryCodes());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $t->id)->count(), 'all sessions ended');
        $this->assertNotSame($oldRemember, $t->remember_token, 'remember-me cookies invalidated');
        $this->assertTrue(ActivityLog::where('action', 'mfa.reset')->where('message', 'like', '%Rafi%Pixel 8%')->exists());

        // Old codes no longer work. Next sign-in requires a new device.
        auth()->logout();
        $this->post('/login', ['email' => 'rafi@msp.test', 'password' => 'Password123'])->assertRedirect(route('mfa.setup'));
        $this->get(route('mfa.challenge'))->assertRedirect(route('mfa.setup'));
        $this->get(route('mfa.setup'))->assertOk()->assertSee('Name this device');
        $new = session(Mfa::SETUP_SECRET);
        $this->post(route('mfa.confirm'), ['code' => Totp::code($new, Totp::currentStep()), 'device_name' => 'Rafi new iPhone'])->assertRedirect(route('mfa.recovery'));
        $this->assertAuthenticatedAs($this->tech);
        $this->assertSame('Rafi new iPhone', $this->tech->fresh()->mfa_device_name);
    }

    public function test_user_removes_own_lost_device_with_password_and_must_register_before_continuing(): void
    {
        $this->actingAs($this->tech);
        $this->get(route('profile.show'))->assertOk()->assertSee('Lost your phone?')->assertSee("Rafi's Pixel 8");

        $this->post(route('mfa.remove'), ['confirmation' => 'wrong-password'])->assertSessionHasErrors('remove_confirmation');
        $this->assertTrue($this->tech->fresh()->hasMfa());

        $this->post(route('mfa.remove'), ['confirmation' => 'Password123'])->assertRedirect(route('mfa.setup'));
        $this->assertFalse($this->tech->fresh()->hasMfa());

        // Cannot use the app until a new device is registered.
        $this->get(route('dashboard'))->assertRedirect(route('mfa.setup'));
        $this->get(route('mfa.setup'))->assertOk()->assertSee('Register your new device')->assertSee('Sign out and register later');
        $new = session(Mfa::SETUP_SECRET);
        $this->post(route('mfa.confirm'), ['code' => Totp::code($new, Totp::currentStep()), 'device_name' => 'Replacement phone'])->assertRedirect(route('mfa.recovery'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_user_can_remove_own_device_with_a_recovery_code(): void
    {
        $codes = $this->tech->regenerateRecoveryCodes();
        $this->actingAs($this->tech);
        $this->post(route('mfa.remove'), ['confirmation' => $codes[3]])->assertRedirect(route('mfa.setup'));
        $this->assertFalse($this->tech->fresh()->hasMfa());
    }

    public function test_only_user_managers_can_remove_other_users_devices(): void
    {
        $other = User::create(['name' => 'Ro', 'email' => 'ro@msp.test', 'password' => 'Password123',
            'role_id' => Role::byKey('read_only')->id, 'all_tenants' => true, 'is_active' => true]);
        $other->enableMfa(Totp::generateSecret(), Totp::currentStep(), 'Ro phone');

        $this->actingAs($this->tech);
        $this->post(route('users.mfa.reset', $other))->assertForbidden();
        $this->assertTrue($other->fresh()->hasMfa());
    }
}
