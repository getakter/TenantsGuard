<?php

namespace Tests;

use App\Services\Mfa;
use App\Support\Totp;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Acting as a user in tests means a session that already completed MFA.
     * The MFA flow itself is covered end to end in MfaTest.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        // A fully signed-in user always has a registered MFA device.
        if (method_exists($user, 'hasMfa') && ! $user->hasMfa()) {
            $user->enableMfa(Totp::generateSecret(), Totp::currentStep() - 10, 'Test device');
        }
        $this->withSession([Mfa::VERIFIED => $user->getAuthIdentifier()]);

        return parent::actingAs($user, $guard);
    }
}
