<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Mfa;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenantsguard.admin.password' => 'secret-pass-123']);
        $this->seed();
        $this->admin = User::first();
    }

    private function staff(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $role.'@msp.test', 'password' => 'Password123',
            'role_id' => Role::byKey($role)->id, 'all_tenants' => true, 'is_active' => true]);
    }

    private function enroll(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->enableMfa($secret, Totp::currentStep() - 5);

        return $secret;
    }

    private function now(string $secret, int $offset = 0): string
    {
        return Totp::code($secret, Totp::currentStep() + $offset);
    }

    public function test_totp_matches_rfc_6238_vectors(): void
    {
        $s = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
        $this->assertSame('287082', Totp::code($s, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($s, intdiv(1111111109, 30)));
        $this->assertSame('279037', Totp::code($s, intdiv(2000000000, 30)));
        $this->assertSame(intdiv(59, 30), Totp::verify($s, '287082', null, 1, 59));
        $this->assertNull(Totp::verify($s, '287082', intdiv(59, 30), 1, 59), 'replayed step rejected');
    }

    public function test_every_role_must_enroll_and_then_use_mfa(): void
    {
        foreach (['global_admin', 'technician', 'read_only'] as $role) {
            $user = $role === 'global_admin' ? $this->admin : $this->staff($role);
            $password = $role === 'global_admin' ? 'secret-pass-123' : 'Password123';

            // First sign-in: password alone does not sign in; enrollment is forced.
            $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect(route('mfa.setup'));
            $this->assertGuest();
            $this->get(route('dashboard'))->assertRedirect(route('login'));
            $this->get(route('mfa.setup'))->assertOk()->assertSee('<svg', false)->assertSee($user->email);
            $secret = session(Mfa::SETUP_SECRET);

            $this->post(route('mfa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
            $this->post(route('mfa.confirm'), ['code' => $this->now($secret)])->assertRedirect(route('mfa.recovery'));
            $this->assertAuthenticatedAs($user);
            $this->get(route('mfa.recovery'))->assertOk()->assertSee('Save your recovery codes');
            $this->assertTrue($user->fresh()->hasMfa(), $role);
            $this->assertSame(10, $user->fresh()->remainingRecoveryCodes());
            $this->get(route('dashboard'))->assertOk();
            $this->post(route('logout'));

            // Next sign-in: challenge with the authenticator.
            $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect(route('mfa.challenge'));
            $this->get(route('mfa.challenge'))->assertOk()->assertSee('Two-step verification');
            $this->post(route('mfa.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
            $this->assertGuest();
            $this->post(route('mfa.verify'), ['code' => $this->now($secret, 1)])->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'));
        }
    }

    public function test_codes_cannot_be_replayed_and_attempts_are_limited(): void
    {
        $user = $this->staff('technician');
        $secret = $this->enroll($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123']);
        $code = $this->now($secret);
        $this->post(route('mfa.verify'), ['code' => $code])->assertRedirect(route('dashboard'));
        $this->post(route('logout'));

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123']);
        $this->post(route('mfa.verify'), ['code' => $code])->assertSessionHasErrors('code'); // same code again
        $this->assertGuest();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('mfa.verify'), ['code' => '000000']);
        }
        $this->post(route('mfa.verify'), ['code' => '111111'])->assertRedirect(route('login'));
        $this->assertNull(session(Mfa::PENDING), 'pending sign-in dropped after 5 failures');
        $this->get(route('mfa.challenge'))->assertRedirect(route('login'));
    }

    public function test_recovery_codes_work_once(): void
    {
        $user = $this->staff('read_only');
        $this->enroll($user);
        $codes = $user->regenerateRecoveryCodes();

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123']);
        $this->post(route('mfa.verify'), ['code' => strtoupper($codes[0])])->assertRedirect(route('profile.show'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(9, $user->fresh()->remainingRecoveryCodes());
        $this->post(route('logout'));

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123']);
        $this->post(route('mfa.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_existing_sessions_without_mfa_are_challenged(): void
    {
        $user = $this->staff('technician');
        $this->enroll($user);

        // A session that was signed in before MFA existed (no verified marker).
        $this->be($user);
        $this->get(route('dashboard'))->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->assertSame($user->id, session(Mfa::PENDING)['id']);
    }

    public function test_admin_can_reset_a_users_mfa_and_user_re_enrolls(): void
    {
        $tech = $this->staff('technician');
        $this->enroll($tech);
        $this->actingAs($this->admin);

        $this->get(route('users.index'))->assertOk()->assertSee('Remove MFA device')->assertSee('Registered');
        $this->post(route('users.mfa.reset', $tech))->assertSessionHas('status');
        $this->assertFalse($tech->fresh()->hasMfa());
        $this->post(route('users.mfa.reset', $this->admin))->assertSessionHasErrors('user');

        // A technician cannot reset anyone.
        $this->actingAs($tech);
        $this->post(route('users.mfa.reset', $this->admin))->assertForbidden();

        auth()->logout();
        $this->post('/login', ['email' => $tech->email, 'password' => 'Password123'])->assertRedirect(route('mfa.setup'));
    }

    public function test_user_can_regenerate_codes_and_replace_authenticator(): void
    {
        $user = $this->staff('technician');
        $old = $this->enroll($user);
        $this->actingAs($user);

        $this->get(route('profile.show'))->assertOk()->assertSee('Two-step verification');
        $this->post(route('mfa.regenerate'), ['code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->post(route('mfa.regenerate'), ['code' => $this->now($old)])->assertRedirect(route('mfa.recovery'));

        $this->post(route('mfa.replace'), ['code' => $this->now($old, 1)])->assertRedirect(route('mfa.setup'));
        $this->get(route('mfa.setup'))->assertOk()->assertSee('Replace your authenticator');
        $new = session(Mfa::SETUP_SECRET);
        $this->post(route('mfa.confirm'), ['code' => $this->now($new)])->assertRedirect(route('mfa.recovery'));
        $this->assertSame($new, $user->fresh()->mfa_secret);
        $this->assertNotSame($old, $new);
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $user = $this->staff('technician');
        $secret = $this->enroll($user);
        $raw = \DB::table('users')->where('id', $user->id)->value('mfa_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);
    }
}
