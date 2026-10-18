<?php

namespace App\Services\Checks;

use App\Services\Graph\GraphException;
use Carbon\Carbon;

/**
 * The control library. Each control:
 *  - id, title, category, severity, description, remediation
 *  - frameworks: cis (CIS Microsoft 365 Foundations Benchmark), cisv8, nist (CSF 2.0), e8 (Essential Eight), ins (cyber-insurance relevant)
 *  - license: null|'p1'|'p2'|'intune' (marked Not applicable when the tenant lacks it)
 *  - permission: Graph permission needed to evaluate it
 *  - run(CheckContext): result array {status, summary, evidence, affected, fix}
 *  - fix(CheckContext, array $opts): string message (optional)
 *
 * Framework references are best-effort mappings and must be validated against the
 * benchmark versions you report on.
 */
final class Catalog
{
    public const FRAMEWORKS = [
        'cis' => 'CIS Microsoft 365 Foundations',
        'cisv8' => 'CIS Controls v8.1',
        'nist' => 'NIST CSF 2.0',
        'e8' => 'Essential Eight',
        'ins' => 'Cyber insurance',
    ];

    public const CATEGORIES = ['Identity', 'Privileged access', 'Applications', 'Collaboration', 'Email', 'Devices', 'Tenant'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    public const STATUSES = [
        'pass' => 'Pass',
        'fail' => 'Fail',
        'manual' => 'Manual review',
        'na' => 'Not applicable',
        'error' => 'Could not check',
        'info' => 'Info',
    ];

    public const TG_PREFIX = 'TenantsGuard: ';

    private static ?array $cache = null;

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::$cache ??= collect(self::definitions())->keyBy('id')->all();
    }

    public static function find(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    // ------------------------------------------------------------------ helpers

    private static function r(string $status, string $summary, array $evidence = [], array $affected = [], ?array $fix = null): array
    {
        return compact('status', 'summary', 'evidence', 'affected', 'fix');
    }

    private static function fixMeta(string $label, string $impact): array
    {
        return ['label' => $label, 'impact' => $impact];
    }

    private static function grants(array $p): array
    {
        return $p['grantControls']['builtInControls'] ?? [];
    }

    private static function requiresMfa(array $p): bool
    {
        return in_array('mfa', self::grants($p), true) || ! empty($p['grantControls']['authenticationStrength']);
    }

    private static function blocks(array $p): bool
    {
        return in_array('block', self::grants($p), true);
    }

    private static function allUsers(array $p): bool
    {
        return in_array('All', $p['conditions']['users']['includeUsers'] ?? [], true);
    }

    private static function allApps(array $p): bool
    {
        return in_array('All', $p['conditions']['applications']['includeApplications'] ?? [], true);
    }

    private static function includesRole(array $p, string $roleId): bool
    {
        return in_array($roleId, $p['conditions']['users']['includeRoles'] ?? [], true);
    }

    private static function enabledPolicies(CheckContext $c): array
    {
        return array_values(array_filter($c->conditionalAccess(), fn ($p) => ($p['state'] ?? '') === 'enabled'));
    }

    private static function policyNames(array $policies): array
    {
        return array_map(fn ($p) => $p['displayName'] ?? $p['id'], $policies);
    }

    private static function caTemplates(): array
    {
        $roles = array_keys(Roles::PRIVILEGED);

        return [
            'mfa_all' => [
                'displayName' => self::TG_PREFIX.'Require MFA for all users',
                'conditions' => ['users' => ['includeUsers' => ['All'], 'excludeUsers' => []], 'applications' => ['includeApplications' => ['All']], 'clientAppTypes' => ['all']],
                'grantControls' => ['operator' => 'OR', 'builtInControls' => ['mfa']],
            ],
            'mfa_admins' => [
                'displayName' => self::TG_PREFIX.'Require MFA for administrators',
                'conditions' => ['users' => ['includeRoles' => $roles], 'applications' => ['includeApplications' => ['All']], 'clientAppTypes' => ['all']],
                'grantControls' => ['operator' => 'OR', 'builtInControls' => ['mfa']],
            ],
            'block_legacy' => [
                'displayName' => self::TG_PREFIX.'Block legacy authentication',
                'conditions' => ['users' => ['includeUsers' => ['All'], 'excludeUsers' => []], 'applications' => ['includeApplications' => ['All']], 'clientAppTypes' => ['exchangeActiveSync', 'other']],
                'grantControls' => ['operator' => 'OR', 'builtInControls' => ['block']],
            ],
            'admin_session' => [
                'displayName' => self::TG_PREFIX.'Admin sign-in frequency 4h, no persistent browser',
                'conditions' => ['users' => ['includeRoles' => $roles], 'applications' => ['includeApplications' => ['All']], 'clientAppTypes' => ['all']],
                'sessionControls' => [
                    'signInFrequency' => ['value' => 4, 'type' => 'hours', 'isEnabled' => true],
                    'persistentBrowser' => ['mode' => 'never', 'isEnabled' => true],
                ],
            ],
            'risky_signin' => [
                'displayName' => self::TG_PREFIX.'Require MFA for risky sign-ins',
                'conditions' => ['users' => ['includeUsers' => ['All'], 'excludeUsers' => []], 'applications' => ['includeApplications' => ['All']], 'clientAppTypes' => ['all'], 'signInRiskLevels' => ['high', 'medium']],
                'grantControls' => ['operator' => 'OR', 'builtInControls' => ['mfa']],
            ],
        ];
    }

    private static function tgPolicy(CheckContext $c, string $key): ?array
    {
        $name = self::caTemplates()[$key]['displayName'];

        return collect($c->conditionalAccess())->firstWhere('displayName', $name);
    }

    /** Fix metadata for a two-step Conditional Access guided workflow. */
    private static function caFixMeta(CheckContext $c, string $key, string $impact): ?array
    {
        if ($c->securityDefaultsOn()) {
            return null;
        }
        $existing = self::tgPolicy($c, $key);
        if ($existing && $existing['state'] !== 'enabled') {
            return ['label' => 'Step 2 of 2: Enforce the report-only policy', 'impact' => $impact.' Before enforcing, review the report-only results in the sign-in logs and exclude your break-glass accounts.', 'step' => 2];
        }
        if ($existing) {
            return null;
        }

        return ['label' => 'Step 1 of 2: Create policy in report-only mode', 'impact' => 'No user impact. The policy only logs what it would have done so you can review it.', 'step' => 1];
    }

    private static function caFix(CheckContext $c, string $key, array $opts): string
    {
        $c->forget('ca');
        $tpl = self::caTemplates()[$key];
        $existing = self::tgPolicy($c, $key);

        if ($existing && $existing['state'] !== 'enabled') {
            if (! empty($opts['safe'])) {
                return 'Skipped: "'.$tpl['displayName'].'" is in report-only mode and waiting for review before enforcement.';
            }
            $c->graph->patch('/identity/conditionalAccess/policies/'.$existing['id'], ['state' => 'enabled']);

            return 'Enforced Conditional Access policy "'.$tpl['displayName'].'".';
        }
        if ($existing) {
            return '"'.$tpl['displayName'].'" is already enforced.';
        }
        $c->graph->post('/identity/conditionalAccess/policies', $tpl + ['state' => 'enabledForReportingButNotEnforced']);

        return 'Created "'.$tpl['displayName'].'" in report-only mode. Review it in the sign-in logs, add break-glass exclusions, then run step 2 to enforce it.';
    }

    private static function userRow(array $u, string $detail = ''): array
    {
        return ['name' => $u['displayName'] ?? $u['userDisplayName'] ?? '', 'id' => $u['userPrincipalName'] ?? $u['id'] ?? '', 'detail' => $detail];
    }

    private static function patchAuthz(CheckContext $c, array $body, string $message): string
    {
        $c->graph->patch('/policies/authorizationPolicy', $body);

        return $message;
    }

    private static function setAuthMethod(CheckContext $c, string $id, string $type, string $state): void
    {
        $c->graph->patch('/policies/authenticationMethodsPolicy/authenticationMethodConfigurations/'.$id, [
            '@odata.type' => '#microsoft.graph.'.$type,
            'state' => $state,
        ]);
    }

    private static function authMethodState(CheckContext $c, string $id): ?string
    {
        foreach ($c->authMethodsPolicy()['authenticationMethodConfigurations'] ?? [] as $cfg) {
            if (strcasecmp($cfg['id'] ?? '', $id) === 0) {
                return $cfg['state'] ?? null;
            }
        }

        return null;
    }

    /** Per-domain DNS evaluation helper for SPF / DKIM / DMARC. */
    private static function perDomain(CheckContext $c, callable $eval, string $okMsg, string $failMsg): array
    {
        $domains = $c->emailDomains();
        if (! $domains) {
            return self::r('na', 'No verified custom email domains found.');
        }
        $affected = [];
        $evidence = [];
        foreach ($domains as $d) {
            [$ok, $detail] = $eval($d);
            $evidence[$d] = $detail;
            if (! $ok) {
                $affected[] = ['name' => $d, 'id' => $d, 'detail' => $detail];
            }
        }

        return $affected
            ? self::r('fail', sprintf($failMsg, count($affected), count($domains)), $evidence, $affected)
            : self::r('pass', sprintf($okMsg, count($domains)), $evidence);
    }

    // ------------------------------------------------------------------ catalog

    private static function definitions(): array
    {
        return [
            // ============================== IDENTITY ==============================
            [
                'id' => 'ID-01', 'category' => 'Identity', 'severity' => 'critical', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'Sign-ins are protected by security defaults or Conditional Access',
                'description' => 'Without security defaults or enforced Conditional Access policies, accounts can sign in with a password alone.',
                'remediation' => 'Tenants with Entra ID P1 should use Conditional Access (see ID-02 to ID-04). Tenants without P1 should turn on security defaults in Entra admin center > Overview > Properties > Manage security defaults.',
                'frameworks' => ['cis' => null, 'cisv8' => '6.3, 6.5', 'nist' => 'PR.AA-03', 'e8' => 'Multi-factor authentication', 'ins' => true],
                'run' => function (CheckContext $c) {
                    $sd = $c->securityDefaultsOn();
                    try {
                        $enabled = self::enabledPolicies($c);
                    } catch (GraphException) {
                        $enabled = [];
                    }
                    if ($sd) {
                        return self::r('pass', 'Security defaults are enabled.', ['securityDefaults' => true]);
                    }
                    if ($enabled) {
                        return self::r('pass', count($enabled).' Conditional Access policies are enforced.', ['enforcedPolicies' => self::policyNames($enabled)]);
                    }
                    $fix = $c->licenses()['p1'] ? null : self::fixMeta('Turn on security defaults', 'All users must register for MFA within 14 days. Legacy authentication is blocked.');

                    return self::r('fail', 'Security defaults are off and no Conditional Access policy is enforced.', ['securityDefaults' => false, 'enforcedPolicies' => []], [], $fix);
                },
                'fix' => function (CheckContext $c) {
                    $c->graph->patch('/policies/identitySecurityDefaultsEnforcementPolicy', ['isEnabled' => true]);

                    return 'Security defaults turned on.';
                },
            ],
            [
                'id' => 'ID-02', 'category' => 'Identity', 'severity' => 'critical', 'license' => 'p1', 'permission' => 'Policy.Read.All',
                'title' => 'MFA is required for all users',
                'description' => 'An enforced Conditional Access policy should require multifactor authentication for all users and all cloud apps.',
                'remediation' => 'Create a Conditional Access policy that targets All users and All cloud apps and grants access with "Require multifactor authentication". Exclude only documented break-glass accounts.',
                'frameworks' => ['cis' => '5.2.2.2', 'cisv8' => '6.3', 'nist' => 'PR.AA-03', 'e8' => 'Multi-factor authentication', 'ins' => true],
                'run' => function (CheckContext $c) {
                    if ($c->securityDefaultsOn()) {
                        return self::r('pass', 'Security defaults enforce MFA for all users.');
                    }
                    $match = array_filter(self::enabledPolicies($c), fn ($p) => self::allUsers($p) && self::allApps($p) && self::requiresMfa($p));
                    if ($match) {
                        return self::r('pass', 'Enforced by: '.implode(', ', self::policyNames($match)).'.', ['policies' => self::policyNames($match)]);
                    }
                    $pending = self::tgPolicy($c, 'mfa_all');

                    return self::r('fail', $pending ? 'A TenantsGuard policy exists in report-only mode and is waiting to be enforced.' : 'No enforced policy requires MFA for all users.',
                        ['reportOnlyPolicy' => $pending['displayName'] ?? null], [],
                        self::caFixMeta($c, 'mfa_all', 'Every user will be prompted for MFA and must register a method.'));
                },
                'fix' => fn (CheckContext $c, array $o) => self::caFix($c, 'mfa_all', $o),
            ],
            [
                'id' => 'ID-03', 'category' => 'Privileged access', 'severity' => 'critical', 'license' => 'p1', 'permission' => 'Policy.Read.All',
                'title' => 'MFA is required for administrative roles',
                'description' => 'Privileged roles must always be challenged for MFA.',
                'remediation' => 'Create a Conditional Access policy that targets the privileged directory roles and requires MFA (ideally phishing-resistant MFA through authentication strengths).',
                'frameworks' => ['cis' => '5.2.2.1', 'cisv8' => '6.5', 'nist' => 'PR.AA-05', 'e8' => 'Multi-factor authentication', 'ins' => true],
                'run' => function (CheckContext $c) {
                    if ($c->securityDefaultsOn()) {
                        return self::r('pass', 'Security defaults enforce MFA for administrators.');
                    }
                    $match = array_filter(self::enabledPolicies($c), fn ($p) => self::allApps($p) && self::requiresMfa($p)
                        && (self::allUsers($p) || self::includesRole($p, Roles::GLOBAL_ADMIN)));
                    if ($match) {
                        return self::r('pass', 'Enforced by: '.implode(', ', self::policyNames($match)).'.', ['policies' => self::policyNames($match)]);
                    }

                    return self::r('fail', 'No enforced policy requires MFA for Global Administrators.', [], [],
                        self::caFixMeta($c, 'mfa_admins', 'Users in privileged roles will be prompted for MFA on every sign-in session.'));
                },
                'fix' => fn (CheckContext $c, array $o) => self::caFix($c, 'mfa_admins', $o),
            ],
            [
                'id' => 'ID-04', 'category' => 'Identity', 'severity' => 'high', 'license' => 'p1', 'permission' => 'Policy.Read.All',
                'title' => 'Legacy authentication is blocked',
                'description' => 'Legacy protocols (POP, IMAP, SMTP AUTH, older Office clients) cannot do MFA and are the main target of password spraying.',
                'remediation' => 'Create a Conditional Access policy for All users where Client apps = Exchange ActiveSync and Other clients, with Grant = Block access.',
                'frameworks' => ['cis' => '5.2.2.3', 'cisv8' => '4.8', 'nist' => 'PR.PS-01', 'e8' => 'Multi-factor authentication', 'ins' => true],
                'run' => function (CheckContext $c) {
                    if ($c->securityDefaultsOn()) {
                        return self::r('pass', 'Security defaults block legacy authentication.');
                    }
                    $match = array_filter(self::enabledPolicies($c), function ($p) {
                        $types = $p['conditions']['clientAppTypes'] ?? [];

                        return self::blocks($p) && self::allUsers($p) && in_array('other', $types, true) && in_array('exchangeActiveSync', $types, true);
                    });
                    if ($match) {
                        return self::r('pass', 'Blocked by: '.implode(', ', self::policyNames($match)).'.', ['policies' => self::policyNames($match)]);
                    }

                    return self::r('fail', 'No enforced policy blocks legacy authentication protocols.', [], [],
                        self::caFixMeta($c, 'block_legacy', 'Devices and apps that use POP, IMAP, SMTP AUTH or old Office clients will stop working.'));
                },
                'fix' => fn (CheckContext $c, array $o) => self::caFix($c, 'block_legacy', $o),
            ],
            [
                'id' => 'ID-05', 'category' => 'Privileged access', 'severity' => 'medium', 'license' => 'p1', 'permission' => 'Policy.Read.All',
                'title' => 'Admin sessions have a limited sign-in frequency',
                'description' => 'Administrative sessions should expire quickly and never persist in the browser.',
                'remediation' => 'Create a Conditional Access policy for privileged roles with Session > Sign-in frequency (4 hours or less) and Persistent browser session = Never persistent.',
                'frameworks' => ['cis' => '5.2.2.4', 'cisv8' => '6.5', 'nist' => 'PR.AA-05', 'e8' => 'Restrict administrative privileges', 'ins' => false],
                'run' => function (CheckContext $c) {
                    $match = array_filter(self::enabledPolicies($c), fn ($p) => self::includesRole($p, Roles::GLOBAL_ADMIN)
                        && ($p['sessionControls']['signInFrequency']['isEnabled'] ?? false));
                    if ($match) {
                        return self::r('pass', 'Enforced by: '.implode(', ', self::policyNames($match)).'.');
                    }
                    $fix = self::caFixMeta($c, 'admin_session', 'Administrators re-authenticate every 4 hours.');

                    return self::r('fail', 'No enforced policy limits administrator session lifetime.'.($c->securityDefaultsOn() ? ' Security defaults must be turned off before Conditional Access can be used.' : ''), [], [], $fix);
                },
                'fix' => fn (CheckContext $c, array $o) => self::caFix($c, 'admin_session', $o),
            ],
            [
                'id' => 'ID-06', 'category' => 'Identity', 'severity' => 'high', 'license' => 'p2', 'permission' => 'Policy.Read.All',
                'title' => 'Risky sign-ins are challenged',
                'description' => 'Entra ID Protection sign-in risk should trigger MFA or a block.',
                'remediation' => 'Create a Conditional Access policy for All users with Sign-in risk = High, Medium and Grant = Require MFA.',
                'frameworks' => ['cis' => '5.2.2.7', 'cisv8' => '13.3', 'nist' => 'DE.CM-03', 'e8' => null, 'ins' => true],
                'run' => function (CheckContext $c) {
                    $match = array_filter(self::enabledPolicies($c), fn ($p) => ! empty($p['conditions']['signInRiskLevels'])
                        && (self::requiresMfa($p) || self::blocks($p)));
                    if ($match) {
                        return self::r('pass', 'Enforced by: '.implode(', ', self::policyNames($match)).'.');
                    }

                    return self::r('fail', 'No enforced policy responds to sign-in risk.', [], [],
                        self::caFixMeta($c, 'risky_signin', 'Users with medium or high risk sign-ins are prompted for MFA.'));
                },
                'fix' => fn (CheckContext $c, array $o) => self::caFix($c, 'risky_signin', $o),
            ],
            [
                'id' => 'ID-07', 'category' => 'Identity', 'severity' => 'high', 'license' => 'p2', 'permission' => 'IdentityRiskyUser.Read.All',
                'title' => 'No users are flagged as at risk',
                'description' => 'Users flagged at risk by Entra ID Protection may be compromised.',
                'remediation' => 'Investigate each risky user in Entra ID Protection. Confirm compromise or dismiss, reset the password and revoke sessions.',
                'frameworks' => ['cis' => null, 'cisv8' => '17.4', 'nist' => 'DE.AE-02', 'e8' => null, 'ins' => true],
                'run' => function (CheckContext $c) {
                    $risky = $c->riskyUsers();
                    if (! $risky) {
                        return self::r('pass', 'No users are currently at risk.');
                    }

                    return self::r('fail', count($risky).' users are flagged at risk.', [], array_map(
                        fn ($u) => ['name' => $u['userDisplayName'] ?? '', 'id' => $u['userPrincipalName'] ?? '', 'detail' => 'Risk level: '.($u['riskLevel'] ?? '?')], $risky));
                },
            ],
            [
                'id' => 'ID-08', 'category' => 'Identity', 'severity' => 'high', 'license' => 'p1', 'permission' => 'AuditLog.Read.All',
                'title' => 'All member accounts are registered for MFA',
                'description' => 'Accounts without a registered MFA method can be claimed by whoever signs in first with the password.',
                'remediation' => 'Run an MFA registration campaign (Entra > Protection > Authentication methods > Registration campaign) and follow up with the listed users.',
                'frameworks' => ['cis' => null, 'cisv8' => '6.3', 'nist' => 'PR.AA-03', 'e8' => 'Multi-factor authentication', 'ins' => true],
                'run' => function (CheckContext $c) {
                    $members = array_filter($c->registration(), fn ($u) => strtolower($u['userType'] ?? 'member') === 'member');
                    $missing = array_filter($members, fn ($u) => ! ($u['isMfaRegistered'] ?? false));
                    $ev = ['members' => count($members), 'registered' => count($members) - count($missing)];
                    if (! $missing) {
                        return self::r('pass', 'All '.count($members).' member accounts have MFA registered.', $ev);
                    }

                    return self::r('fail', count($missing).' of '.count($members).' member accounts have no MFA method registered.', $ev,
                        array_map(fn ($u) => self::userRow($u, 'No MFA method registered'), array_values($missing)));
                },
            ],
            [
                'id' => 'ID-09', 'category' => 'Privileged access', 'severity' => 'critical', 'license' => 'p1', 'permission' => 'AuditLog.Read.All',
                'title' => 'All administrators are registered for MFA',
                'description' => 'An administrator without MFA is the highest-impact account takeover risk in the tenant.',
                'remediation' => 'Have each listed administrator register Microsoft Authenticator or a FIDO2 key immediately, or remove their role.',
                'frameworks' => ['cis' => null, 'cisv8' => '6.5', 'nist' => 'PR.AA-05', 'e8' => 'Multi-factor authentication', 'ins' => true],
                'run' => function (CheckContext $c) {
                    $admins = array_filter($c->registration(), fn ($u) => $u['isAdmin'] ?? false);
                    $missing = array_filter($admins, fn ($u) => ! ($u['isMfaRegistered'] ?? false));
                    if (! $missing) {
                        return self::r('pass', 'All '.count($admins).' administrators have MFA registered.');
                    }

                    return self::r('fail', count($missing).' of '.count($admins).' administrators have no MFA method registered.', [],
                        array_map(fn ($u) => self::userRow($u, 'Administrator without MFA'), array_values($missing)));
                },
            ],
            [
                'id' => 'ID-10', 'category' => 'Privileged access', 'severity' => 'high', 'license' => null, 'permission' => 'RoleManagement.Read.Directory',
                'title' => 'Between two and four Global Administrators',
                'description' => 'One Global Administrator is a single point of failure. More than four widens the attack surface.',
                'remediation' => 'Keep 2 to 4 Global Administrators (including break-glass accounts). Move others to least-privilege roles such as User, Exchange or Helpdesk Administrator.',
                'frameworks' => ['cis' => '1.1.3', 'cisv8' => '5.4', 'nist' => 'PR.AA-05', 'e8' => 'Restrict administrative privileges', 'ins' => true],
                'run' => function (CheckContext $c) {
                    $ga = collect($c->directoryRoles())->firstWhere('roleTemplateId', Roles::GLOBAL_ADMIN);
                    $members = array_values(array_filter($ga['members'] ?? [], fn ($m) => str_contains($m['@odata.type'] ?? '#microsoft.graph.user', 'user')));
                    $n = count($members);
                    $rows = array_map(fn ($m) => self::userRow($m, 'Global Administrator'), $members);
                    if ($n >= 2 && $n <= 4) {
                        return self::r('pass', "{$n} Global Administrators.", ['count' => $n], $rows);
                    }

                    return self::r('fail', $n < 2 ? "Only {$n} Global Administrator. Add a second (break-glass) account." : "{$n} Global Administrators. Reduce to four or fewer.", ['count' => $n], $rows);
                },
            ],
            [
                'id' => 'ID-11', 'category' => 'Privileged access', 'severity' => 'high', 'license' => null, 'permission' => 'Directory.Read.All',
                'title' => 'Privileged accounts are cloud-only',
                'description' => 'Admin accounts synced from on-premises AD let an on-premises compromise take over Microsoft 365.',
                'remediation' => 'Create dedicated cloud-only admin accounts (user@tenant.onmicrosoft.com) and remove privileged roles from synced accounts.',
                'frameworks' => ['cis' => '1.1.1', 'cisv8' => '5.4', 'nist' => 'PR.AA-05', 'e8' => 'Restrict administrative privileges', 'ins' => false],
                'run' => function (CheckContext $c) {
                    $users = $c->usersById();
                    $bad = [];
                    foreach ($c->directoryRoles() as $role) {
                        if (! isset(Roles::PRIVILEGED[$role['roleTemplateId'] ?? ''])) {
                            continue;
                        }
                        foreach ($role['members'] ?? [] as $m) {
                            if ($users[$m['id']]['onPremisesSyncEnabled'] ?? false) {
                                $bad[$m['id']] = self::userRow($users[$m['id']], 'Synced account holds '.$role['displayName']);
                            }
                        }
                    }

                    return $bad
                        ? self::r('fail', count($bad).' privileged accounts are synced from on-premises.', [], array_values($bad))
                        : self::r('pass', 'All privileged role holders are cloud-only accounts.');
                },
            ],
            [
                'id' => 'ID-12', 'category' => 'Identity', 'severity' => 'medium', 'license' => 'p1', 'permission' => 'AuditLog.Read.All',
                'title' => 'No dormant enabled accounts (90+ days)',
                'description' => 'Enabled accounts that nobody uses are prime targets because nobody notices when they are abused.',
                'remediation' => 'Disable or delete the listed accounts after confirming with the client. Convert shared mailboxes so they cannot sign in.',
                'frameworks' => ['cis' => null, 'cisv8' => '5.3', 'nist' => 'PR.AA-01', 'e8' => null, 'ins' => true],
                'run' => function (CheckContext $c) {
                    $u = $c->users();
                    if (! $u['signIn']) {
                        return self::r('na', 'Sign-in activity is unavailable (requires Entra ID P1 and AuditLog.Read.All).');
                    }
                    $cutoff = now()->subDays(90);
                    $dormant = array_filter($u['list'], function ($x) use ($cutoff) {
                        if (! ($x['accountEnabled'] ?? false) || ($x['userType'] ?? 'Member') !== 'Member') {
                            return false;
                        }
                        $created = isset($x['createdDateTime']) ? Carbon::parse($x['createdDateTime']) : null;
                        if ($created && $created->gt($cutoff)) {
                            return false;
                        }
                        $last = $x['signInActivity']['lastSignInDateTime'] ?? null;

                        return $last === null || Carbon::parse($last)->lt($cutoff);
                    });
                    if (! $dormant) {
                        return self::r('pass', 'No enabled member account has been idle for 90 days.');
                    }

                    return self::r('fail', count($dormant).' enabled accounts have not signed in for 90+ days.', [], array_map(function ($x) {
                        $last = $x['signInActivity']['lastSignInDateTime'] ?? null;

                        return self::userRow($x, $last ? 'Last sign-in '.Carbon::parse($last)->diffForHumans() : 'Never signed in');
                    }, array_values($dormant)));
                },
            ],
            [
                'id' => 'ID-13', 'category' => 'Identity', 'severity' => 'medium', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'SMS and voice call MFA are disabled',
                'description' => 'SMS and voice codes can be intercepted through SIM swapping and real-time phishing.',
                'remediation' => 'Move users to Microsoft Authenticator or passkeys, then disable SMS and Voice call in Authentication methods > Policies.',
                'frameworks' => ['cis' => null, 'cisv8' => '6.3', 'nist' => 'PR.AA-03', 'e8' => 'Multi-factor authentication', 'ins' => false],
                'run' => function (CheckContext $c) {
                    $on = array_filter(['SMS' => self::authMethodState($c, 'Sms'), 'Voice call' => self::authMethodState($c, 'Voice')], fn ($s) => $s === 'enabled');
                    if (! $on) {
                        return self::r('pass', 'SMS and voice call are disabled as authentication methods.');
                    }

                    return self::r('fail', implode(' and ', array_keys($on)).' enabled as authentication methods.', ['enabled' => array_keys($on)], [],
                        self::fixMeta('Disable SMS and voice call methods', 'Users who only registered a phone number lose that MFA option and are prompted to register Authenticator.'));
                },
                'fix' => function (CheckContext $c) {
                    self::setAuthMethod($c, 'Sms', 'smsAuthenticationMethodConfiguration', 'disabled');
                    self::setAuthMethod($c, 'Voice', 'voiceAuthenticationMethodConfiguration', 'disabled');

                    return 'Disabled SMS and voice call authentication methods.';
                },
            ],
            [
                'id' => 'ID-14', 'category' => 'Identity', 'severity' => 'low', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'Phishing-resistant MFA (passkeys / FIDO2) is available',
                'description' => 'Passkeys and FIDO2 keys resist adversary-in-the-middle phishing.',
                'remediation' => 'Enable Passkey (FIDO2) in Authentication methods > Policies and roll it out to administrators first.',
                'frameworks' => ['cis' => null, 'cisv8' => '6.5', 'nist' => 'PR.AA-03', 'e8' => 'Multi-factor authentication (ML3)', 'ins' => false],
                'run' => function (CheckContext $c) {
                    return self::authMethodState($c, 'Fido2') === 'enabled'
                        ? self::r('pass', 'Passkeys (FIDO2) are enabled.')
                        : self::r('fail', 'Passkeys (FIDO2) are not enabled.', [], [], self::fixMeta('Enable passkeys (FIDO2) for all users', 'No disruption. Users gain an additional option.'));
                },
                'fix' => function (CheckContext $c) {
                    self::setAuthMethod($c, 'Fido2', 'fido2AuthenticationMethodConfiguration', 'enabled');

                    return 'Enabled passkeys (FIDO2).';
                },
            ],
            [
                'id' => 'ID-15', 'category' => 'Identity', 'severity' => 'medium', 'license' => null, 'permission' => 'Domain.Read.All',
                'title' => 'Password expiration is set to never expire',
                'description' => 'Forced periodic password changes lead to weaker, predictable passwords (NIST SP 800-63B).',
                'remediation' => 'Set the password expiration policy to "never expire" for each managed domain, paired with MFA and banned-password protection.',
                'frameworks' => ['cis' => '1.3.1', 'cisv8' => '5.2', 'nist' => 'PR.AA-01', 'e8' => null, 'ins' => false],
                'run' => function (CheckContext $c) {
                    $bad = array_filter($c->domains(), fn ($d) => ($d['isVerified'] ?? false) && ($d['authenticationType'] ?? 'Managed') === 'Managed'
                        && (int) ($d['passwordValidityPeriodInDays'] ?? 2147483647) !== 2147483647);
                    if (! $bad) {
                        return self::r('pass', 'Passwords never expire on all managed domains.');
                    }

                    return self::r('fail', count($bad).' domains force periodic password expiry.', [], array_map(
                        fn ($d) => ['name' => $d['id'], 'id' => $d['id'], 'detail' => 'Expires every '.$d['passwordValidityPeriodInDays'].' days'], array_values($bad)),
                        self::fixMeta('Set passwords to never expire', 'Users stop receiving password expiry prompts.'));
                },
                'fix' => function (CheckContext $c) {
                    $done = [];
                    foreach ($c->domains() as $d) {
                        if (($d['isVerified'] ?? false) && ($d['authenticationType'] ?? 'Managed') === 'Managed' && (int) ($d['passwordValidityPeriodInDays'] ?? 0) !== 2147483647) {
                            $c->graph->patch('/domains/'.rawurlencode($d['id']), ['passwordValidityPeriodInDays' => 2147483647]);
                            $done[] = $d['id'];
                        }
                    }

                    return 'Password expiry disabled for: '.implode(', ', $done).'.';
                },
            ],

            // ============================== APPLICATIONS ==============================
            [
                'id' => 'APP-01', 'category' => 'Applications', 'severity' => 'medium', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'Users cannot register applications',
                'description' => 'User-registered apps can be used to persist access or exfiltrate data outside admin oversight.',
                'remediation' => 'Entra admin center > Users > User settings > set "Users can register applications" to No.',
                'frameworks' => ['cis' => '5.1.2.2', 'cisv8' => '2.5', 'nist' => 'PR.PS-02', 'e8' => 'Application control', 'ins' => false],
                'run' => function (CheckContext $c) {
                    $v = $c->authorizationPolicy()['defaultUserRolePermissions']['allowedToCreateApps'] ?? true;

                    return $v
                        ? self::r('fail', 'Any user can register applications.', ['allowedToCreateApps' => true], [], self::fixMeta('Block users from registering apps', 'Only admins and Application Developers can register apps.'))
                        : self::r('pass', 'Only administrators can register applications.', ['allowedToCreateApps' => false]);
                },
                'fix' => fn (CheckContext $c) => self::patchAuthz($c, ['defaultUserRolePermissions' => ['allowedToCreateApps' => false]], 'Users can no longer register applications.'),
            ],
            [
                'id' => 'APP-02', 'category' => 'Applications', 'severity' => 'high', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'Users cannot consent to third-party apps',
                'description' => 'User consent is the entry point for illicit consent-grant (OAuth phishing) attacks.',
                'remediation' => 'Enterprise apps > Consent and permissions > set "Do not allow user consent", and turn on the admin consent workflow (APP-03).',
                'frameworks' => ['cis' => '5.1.5.1', 'cisv8' => '2.5', 'nist' => 'PR.AA-05', 'e8' => 'Application control', 'ins' => true],
                'run' => function (CheckContext $c) {
                    $assigned = $c->authorizationPolicy()['defaultUserRolePermissions']['permissionGrantPoliciesAssigned'] ?? [];
                    $self = array_values(array_filter($assigned, fn ($p) => str_starts_with($p, 'ManagePermissionGrantsForSelf.')));
                    if (! $self) {
                        return self::r('pass', 'User consent to applications is disabled.', ['assigned' => $assigned]);
                    }
                    $legacy = (bool) array_filter($self, fn ($p) => str_contains($p, 'legacy'));

                    return self::r('fail', $legacy ? 'Users can consent to any application for any permission.' : 'Users can consent to apps for low-impact permissions.', ['assigned' => $assigned], [],
                        self::fixMeta('Turn off user consent', 'Users requesting new apps must go through admin approval.'));
                },
                'fix' => function (CheckContext $c) {
                    $assigned = $c->authorizationPolicy()['defaultUserRolePermissions']['permissionGrantPoliciesAssigned'] ?? [];
                    $keep = array_values(array_filter($assigned, fn ($p) => ! str_starts_with($p, 'ManagePermissionGrantsForSelf.')));

                    return self::patchAuthz($c, ['defaultUserRolePermissions' => ['permissionGrantPoliciesAssigned' => $keep]], 'User consent to applications turned off.');
                },
            ],
            [
                'id' => 'APP-03', 'category' => 'Applications', 'severity' => 'medium', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'Admin consent workflow is enabled',
                'description' => 'With user consent disabled, users need a supported way to request apps.',
                'remediation' => 'Enterprise apps > Admin consent settings > enable the workflow and choose reviewers.',
                'frameworks' => ['cis' => '5.1.5.2', 'cisv8' => '2.5', 'nist' => 'PR.AA-05', 'e8' => 'Application control', 'ins' => false],
                'run' => function (CheckContext $c) {
                    return ($c->adminConsentPolicy()['isEnabled'] ?? false)
                        ? self::r('pass', 'The admin consent request workflow is enabled.')
                        : self::r('fail', 'The admin consent request workflow is disabled.', [], [], self::fixMeta('Enable the admin consent workflow (Global Admins review)', 'Users can request apps. Global Administrators receive review emails.'));
                },
                'fix' => function (CheckContext $c) {
                    $c->graph->put('/policies/adminConsentRequestPolicy', [
                        'isEnabled' => true,
                        'notifyReviewers' => true,
                        'remindersEnabled' => true,
                        'requestDurationInDays' => 30,
                        'reviewers' => [[
                            'query' => "/beta/roleManagement/directory/roleAssignments?\$filter=roleDefinitionId eq '".Roles::GLOBAL_ADMIN."'",
                            'queryType' => 'MicrosoftGraph',
                            'queryRoot' => null,
                        ]],
                    ]);

                    return 'Admin consent workflow enabled with Global Administrators as reviewers.';
                },
            ],
            [
                'id' => 'APP-04', 'category' => 'Applications', 'severity' => 'low', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'Users cannot create new tenants',
                'description' => 'Users creating their own Entra tenants creates shadow IT outside your management.',
                'remediation' => 'Entra admin center > Users > User settings > set "Restrict non-admin users from creating tenants" to Yes.',
                'frameworks' => ['cis' => '5.1.2.3', 'cisv8' => '4.1', 'nist' => 'PR.PS-01', 'e8' => null, 'ins' => false],
                'run' => function (CheckContext $c) {
                    return ($c->authorizationPolicy()['defaultUserRolePermissions']['allowedToCreateTenants'] ?? true)
                        ? self::r('fail', 'Non-admin users can create tenants.', [], [], self::fixMeta('Restrict tenant creation to admins', 'No impact on normal users.'))
                        : self::r('pass', 'Only administrators can create tenants.');
                },
                'fix' => fn (CheckContext $c) => self::patchAuthz($c, ['defaultUserRolePermissions' => ['allowedToCreateTenants' => false]], 'Tenant creation restricted to admins.'),
            ],
            [
                'id' => 'APP-05', 'category' => 'Applications', 'severity' => 'low', 'license' => null, 'permission' => 'Application.Read.All',
                'title' => 'App registration secrets are not expired or expiring',
                'description' => 'Expired secrets break integrations. Long-forgotten apps with credentials are a persistence risk.',
                'remediation' => 'Rotate the listed credentials (prefer certificates or managed identities) and delete unused app registrations.',
                'frameworks' => ['cis' => null, 'cisv8' => '5.1', 'nist' => 'PR.AA-01', 'e8' => null, 'ins' => false],
                'run' => function (CheckContext $c) {
                    $soon = now()->addDays(30);
                    $rows = [];
                    foreach ($c->applications() as $app) {
                        foreach (array_merge($app['passwordCredentials'] ?? [], $app['keyCredentials'] ?? []) as $cred) {
                            if (empty($cred['endDateTime'])) {
                                continue;
                            }
                            $end = Carbon::parse($cred['endDateTime']);
                            if ($end->lt($soon)) {
                                $rows[] = ['name' => $app['displayName'], 'id' => $app['appId'] ?? $app['id'],
                                    'detail' => ($end->isPast() ? 'Expired ' : 'Expires ').$end->diffForHumans().' ('.($cred['displayName'] ?? 'credential').')'];
                            }
                        }
                    }

                    return $rows
                        ? self::r('fail', count($rows).' app credentials are expired or expire within 30 days.', ['apps' => count($c->applications())], $rows)
                        : self::r('pass', 'No app credentials expire within 30 days.', ['apps' => count($c->applications())]);
                },
            ],

            // ============================== COLLABORATION ==============================
            [
                'id' => 'COL-01', 'category' => 'Collaboration', 'severity' => 'medium', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'Guest user access is restricted',
                'description' => 'Guests with member-level access can enumerate all users, groups and directory objects.',
                'remediation' => 'External Identities > External collaboration settings > Guest user access = "restricted to properties and memberships of their own directory objects".',
                'frameworks' => ['cis' => '5.1.6.2', 'cisv8' => '3.3', 'nist' => 'PR.AA-05', 'e8' => null, 'ins' => false],
                'run' => function (CheckContext $c) {
                    $role = $c->authorizationPolicy()['guestUserRoleId'] ?? Roles::GUEST_LIMITED;

                    return match ($role) {
                        Roles::GUEST_RESTRICTED => self::r('pass', 'Guests are restricted to their own directory objects.'),
                        Roles::GUEST_LIMITED => self::r('pass', 'Guests have limited access (default).', [], [], null),
                        default => self::r('fail', 'Guests have the same directory access as members.', ['guestUserRoleId' => $role], [],
                            self::fixMeta('Restrict guest access to their own objects', 'Guests can no longer browse the directory. Teams and SharePoint collaboration keeps working.')),
                    };
                },
                'fix' => fn (CheckContext $c) => self::patchAuthz($c, ['guestUserRoleId' => Roles::GUEST_RESTRICTED], 'Guest access restricted to their own directory objects.'),
            ],
            [
                'id' => 'COL-02', 'category' => 'Collaboration', 'severity' => 'medium', 'license' => null, 'permission' => 'Policy.Read.All',
                'title' => 'Only admins and Guest Inviters can invite guests',
                'description' => 'Letting everyone (including guests) invite external users leads to uncontrolled external access.',
                'remediation' => 'External collaboration settings > Guest invite restrictions = "Only users assigned to specific admin roles can invite".',
                'frameworks' => ['cis' => '5.1.6.3', 'cisv8' => '3.3', 'nist' => 'PR.AA-05', 'e8' => null, 'ins' => false],
                'run' => function (CheckContext $c) {
                    $v = $c->authorizationPolicy()['allowInvitesFrom'] ?? 'everyone';

                    return in_array($v, ['adminsAndGuestInviters', 'none'], true)
                        ? self::r('pass', "Guest invitations are limited ({$v}).")
                        : self::r('fail', "Guest invitations are allowed from: {$v}.", ['allowInvitesFrom' => $v], [],
                            self::fixMeta('Limit invitations to admins and Guest Inviters', 'Regular users can no longer invite guests directly. Assign the Guest Inviter role where needed.'));
                },
                'fix' => fn (CheckContext $c) => self::patchAuthz($c, ['allowInvitesFrom' => 'adminsAndGuestInviters'], 'Guest invitations limited to admins and Guest Inviters.'),
            ],
            [
                'id' => 'COL-03', 'category' => 'Collaboration', 'severity' => 'high', 'license' => null, 'permission' => 'SharePointTenantSettings.Read.All',
                'title' => 'SharePoint and OneDrive do not allow "Anyone" links',
                'description' => 'Anonymous "Anyone" links can be forwarded and opened by anyone without signing in.',
                'remediation' => 'SharePoint admin center > Policies > Sharing > set the external sharing level to "New and existing guests" or more restrictive.',
                'frameworks' => ['cis' => '7.2.3', 'cisv8' => '3.3', 'nist' => 'PR.DS-01', 'e8' => null, 'ins' => true],
                'run' => function (CheckContext $c) {
                    $v = $c->sharepointSettings()['sharingCapability'] ?? 'unknown';

                    return $v === 'externalUserAndGuestSharing'
                        ? self::r('fail', 'Anonymous "Anyone" sharing links are allowed.', ['sharingCapability' => $v], [],
                            self::fixMeta('Restrict to new and existing guests', 'Existing Anyone links stop working. External people must sign in to open shared files.'))
                        : self::r('pass', "External sharing level: {$v}.", ['sharingCapability' => $v]);
                },
                'fix' => function (CheckContext $c) {
                    $c->graph->patch('/admin/sharepoint/settings', ['sharingCapability' => 'externalUserSharingOnly']);

                    return 'SharePoint external sharing restricted to new and existing guests.';
                },
            ],
            [
                'id' => 'COL-04', 'category' => 'Collaboration', 'severity' => 'low', 'license' => null, 'permission' => 'SharePointTenantSettings.Read.All',
                'title' => 'Guests cannot reshare items they do not own',
                'description' => 'Resharing by guests spreads access beyond what the owner intended.',
                'remediation' => 'SharePoint admin center > Sharing > More external sharing settings > clear "Allow guests to share items they don\'t own".',
                'frameworks' => ['cis' => null, 'cisv8' => '3.3', 'nist' => 'PR.DS-01', 'e8' => null, 'ins' => false],
                'run' => function (CheckContext $c) {
                    return ($c->sharepointSettings()['isResharingByExternalUsersEnabled'] ?? false)
                        ? self::r('fail', 'Guests can reshare content they do not own.', [], [], self::fixMeta('Block resharing by guests', 'Guests can no longer pass content on to others.'))
                        : self::r('pass', 'Guests cannot reshare content.');
                },
                'fix' => function (CheckContext $c) {
                    $c->graph->patch('/admin/sharepoint/settings', ['isResharingByExternalUsersEnabled' => false]);

                    return 'Guest resharing blocked.';
                },
            ],

            // ============================== EMAIL (DNS) ==============================
            [
                'id' => 'EML-01', 'category' => 'Email', 'severity' => 'high', 'license' => null, 'permission' => 'Domain.Read.All',
                'title' => 'SPF records are published for every email domain',
                'description' => 'SPF tells receiving servers which hosts may send for your domain.',
                'remediation' => 'Publish a TXT record such as "v=spf1 include:spf.protection.outlook.com -all", adding any third-party senders.',
                'frameworks' => ['cis' => '2.1.8', 'cisv8' => '9.5', 'nist' => 'PR.DS-02', 'e8' => null, 'ins' => true],
                'run' => fn (CheckContext $c) => self::perDomain($c, function ($d) use ($c) {
                    $spf = collect($c->dns->txt($d))->first(fn ($t) => str_starts_with(strtolower(trim($t, '"')), 'v=spf1'));
                    if (! $spf) {
                        return [false, 'No SPF record'];
                    }
                    if (preg_match('/[+?]all\s*$/i', $spf)) {
                        return [false, 'Permissive SPF: '.$spf];
                    }

                    return [true, $spf];
                }, 'SPF is published for all %d email domains.', '%d of %d email domains have a missing or permissive SPF record.'),
            ],
            [
                'id' => 'EML-02', 'category' => 'Email', 'severity' => 'medium', 'license' => null, 'permission' => 'Domain.Read.All',
                'title' => 'DKIM signing is configured for every email domain',
                'description' => 'DKIM cryptographically signs outbound mail so it can be verified and aligned for DMARC.',
                'remediation' => 'Publish the selector1/selector2 CNAME records shown in Defender > Email authentication > DKIM, then enable signing.',
                'frameworks' => ['cis' => '2.1.9', 'cisv8' => '9.5', 'nist' => 'PR.DS-02', 'e8' => null, 'ins' => true],
                'run' => fn (CheckContext $c) => self::perDomain($c, function ($d) use ($c) {
                    $s1 = $c->dns->cname("selector1._domainkey.{$d}");
                    $s2 = $c->dns->cname("selector2._domainkey.{$d}");

                    return ($s1 || $s2) ? [true, 'selector1 → '.($s1 ?? '-').', selector2 → '.($s2 ?? '-')] : [false, 'No Microsoft 365 DKIM selector CNAMEs'];
                }, 'DKIM selectors exist for all %d email domains.', '%d of %d email domains have no DKIM selector records.'),
            ],
            [
                'id' => 'EML-03', 'category' => 'Email', 'severity' => 'high', 'license' => null, 'permission' => 'Domain.Read.All',
                'title' => 'DMARC is enforced (quarantine or reject)',
                'description' => 'A DMARC policy of p=none only monitors. Spoofed mail still reaches inboxes.',
                'remediation' => 'Publish _dmarc TXT "v=DMARC1; p=quarantine; rua=mailto:…" and move to p=reject once reports are clean.',
                'frameworks' => ['cis' => '2.1.10', 'cisv8' => '9.5', 'nist' => 'PR.DS-02', 'e8' => null, 'ins' => true],
                'run' => fn (CheckContext $c) => self::perDomain($c, function ($d) use ($c) {
                    $rec = collect($c->dns->txt("_dmarc.{$d}"))->first(fn ($t) => str_starts_with(strtolower(trim($t, '"')), 'v=dmarc1'));
                    if (! $rec) {
                        return [false, 'No DMARC record'];
                    }
                    preg_match('/\bp=(\w+)/i', $rec, $m);
                    $p = strtolower($m[1] ?? 'none');

                    return in_array($p, ['quarantine', 'reject'], true) ? [true, $rec] : [false, "Policy p={$p}: {$rec}"];
                }, 'DMARC is enforced on all %d email domains.', '%d of %d email domains have no enforced DMARC policy.'),
            ],

            // ============================== DEVICES ==============================
            [
                'id' => 'DEV-01', 'category' => 'Devices', 'severity' => 'medium', 'license' => 'intune', 'permission' => 'DeviceManagementConfiguration.Read.All',
                'title' => 'Intune device compliance policies exist',
                'description' => 'Without compliance policies there is no definition of a healthy device for Conditional Access to require.',
                'remediation' => 'Create compliance policies per platform (encryption, OS version, Defender, password) and require compliant devices in Conditional Access.',
                'frameworks' => ['cis' => null, 'cisv8' => '4.1', 'nist' => 'PR.PS-01', 'e8' => 'Patch operating systems', 'ins' => true],
                'run' => function (CheckContext $c) {
                    $p = $c->compliancePolicies();

                    return $p
                        ? self::r('pass', count($p).' device compliance policies are configured.', ['policies' => array_column($p, 'displayName')])
                        : self::r('fail', 'No device compliance policies are configured.');
                },
            ],
            [
                'id' => 'DEV-02', 'category' => 'Devices', 'severity' => 'medium', 'license' => 'intune', 'permission' => 'DeviceManagementManagedDevices.Read.All',
                'title' => 'All managed devices are compliant',
                'description' => 'Non-compliant devices may be unencrypted, unpatched or missing antivirus.',
                'remediation' => 'Review each device in Intune > Devices > Compliance and remediate, or retire stale devices.',
                'frameworks' => ['cis' => null, 'cisv8' => '4.1', 'nist' => 'PR.PS-02', 'e8' => 'Patch operating systems', 'ins' => true],
                'run' => function (CheckContext $c) {
                    $devices = $c->managedDevices();
                    if (! $devices) {
                        return self::r('na', 'No devices are enrolled in Intune.');
                    }
                    $bad = array_filter($devices, fn ($d) => in_array($d['complianceState'] ?? '', ['noncompliant', 'error', 'conflict'], true));

                    return $bad
                        ? self::r('fail', count($bad).' of '.count($devices).' managed devices are non-compliant.', [], array_map(
                            fn ($d) => ['name' => $d['deviceName'], 'id' => $d['userPrincipalName'] ?? '', 'detail' => ($d['operatingSystem'] ?? '').' - '.$d['complianceState']], array_values($bad)))
                        : self::r('pass', 'All '.count($devices).' managed devices are compliant.');
                },
            ],

            // ============================== TENANT ==============================
            [
                'id' => 'TEN-01', 'category' => 'Tenant', 'severity' => 'info', 'license' => null, 'permission' => 'SecurityEvents.Read.All',
                'title' => 'Microsoft Secure Score',
                'description' => 'Microsoft\'s own posture score, recorded here for trend reporting.',
                'remediation' => 'Review improvement actions in the Microsoft Defender portal > Secure Score.',
                'frameworks' => ['cis' => null, 'cisv8' => null, 'nist' => 'ID.RA-01', 'e8' => null, 'ins' => false],
                'run' => function (CheckContext $c) {
                    $s = $c->secureScore();
                    if (! $s) {
                        return self::r('na', 'Secure Score has not been calculated for this tenant yet.');
                    }
                    $pct = $s['maxScore'] > 0 ? round(100 * $s['currentScore'] / $s['maxScore']) : 0;

                    return self::r('info', "Secure Score {$s['currentScore']} / {$s['maxScore']} ({$pct}%).", ['current' => $s['currentScore'], 'max' => $s['maxScore'], 'percent' => $pct]);
                },
            ],
            [
                'id' => 'TEN-02', 'category' => 'Tenant', 'severity' => 'high', 'license' => null, 'permission' => null,
                'title' => 'Unified audit log search is enabled',
                'description' => 'Without the unified audit log there is no forensic trail for an incident.',
                'remediation' => 'Verify with Exchange Online PowerShell: Get-AdminAuditLogConfig | Select UnifiedAuditLogIngestionEnabled. Enable with Set-AdminAuditLogConfig -UnifiedAuditLogIngestionEnabled $true.',
                'frameworks' => ['cis' => '3.1.1', 'cisv8' => '8.2', 'nist' => 'DE.CM-09', 'e8' => null, 'ins' => true],
                'run' => fn () => self::r('manual', 'Not exposed through Microsoft Graph. Verify with Exchange Online PowerShell and record the result as an annotation.'),
            ],
            [
                'id' => 'TEN-03', 'category' => 'Email', 'severity' => 'low', 'license' => null, 'permission' => null,
                'title' => 'External senders are tagged in Outlook',
                'description' => 'An "External" tag helps users spot impersonation of internal staff.',
                'remediation' => 'Exchange Online PowerShell: Set-ExternalInOutlook -Enabled $true. Verify with Get-ExternalInOutlook.',
                'frameworks' => ['cis' => '6.2.3', 'cisv8' => '9.7', 'nist' => 'PR.AT-01', 'e8' => null, 'ins' => false],
                'run' => fn () => self::r('manual', 'Not exposed through Microsoft Graph. Verify with Exchange Online PowerShell and record the result as an annotation.'),
            ],
            [
                'id' => 'TEN-04', 'category' => 'Privileged access', 'severity' => 'medium', 'license' => null, 'permission' => null,
                'title' => 'Break-glass accounts exist and are monitored',
                'description' => 'Two emergency-access accounts, excluded from Conditional Access and monitored for sign-ins, prevent lockout.',
                'remediation' => 'Create two cloud-only Global Administrator accounts with FIDO2 keys, exclude them from CA policies, and alert on any sign-in.',
                'frameworks' => ['cis' => null, 'cisv8' => '5.4', 'nist' => 'PR.AA-05', 'e8' => 'Restrict administrative privileges', 'ins' => false],
                'run' => fn () => self::r('manual', 'Confirm with the client which accounts are break-glass accounts and record them as an annotation.'),
            ],
        ];
    }
}
