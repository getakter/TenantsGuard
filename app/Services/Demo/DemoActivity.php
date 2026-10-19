<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Simulated security activity for demo tenants: Entra audit events, sign-ins, Defender alerts and
 * service incidents, shaped like the Microsoft Graph responses the monitor reads.
 */
final class DemoActivity
{
    /** Initial history: a realistic mix of things an MSP should notice. */
    public static function seed(array $s): array
    {
        $domain = $s['domains'][0]['id'] ?? 'demo.example';
        $admin = collect($s['users'])->first(fn ($u) => ($u['userType'] ?? 'Member') === 'Member');
        $adminUpn = $admin['userPrincipalName'] ?? "admin@{$domain}";
        $victim = collect($s['users'])->firstWhere('displayName', 'Drew Kim') ?? $admin;
        $frontDesk = collect($s['users'])->firstWhere('displayName', 'Front Desk') ?? $admin;

        $s['audits'] = [
            self::audit('Add member to role', 'RoleManagement', $adminUpn, now()->subHours(3), [
                'userPrincipalName' => $victim['userPrincipalName'], 'displayName' => $victim['displayName'], 'type' => 'User',
                'modifiedProperties' => [['displayName' => 'Role.DisplayName', 'newValue' => '"Global Administrator"']],
            ]),
            self::audit('Consent to application', 'ApplicationManagement', $frontDesk['userPrincipalName'], now()->subHours(5), [
                'displayName' => 'PDF Converter Pro', 'type' => 'ServicePrincipal',
                'modifiedProperties' => [['displayName' => 'ConsentContext.IsAdminConsent', 'newValue' => '"False"'], ['displayName' => 'ConsentAction.Permissions', 'newValue' => '"Mail.Read Files.ReadWrite.All offline_access"']],
            ]),
            self::audit('Update conditional access policy', 'Policy', $adminUpn, now()->subHours(20), [
                'displayName' => 'Require MFA for administrators', 'type' => 'Policy', 'modifiedProperties' => [],
            ]),
            self::audit('Update user', 'UserManagement', $adminUpn, now()->subHours(6), [
                'userPrincipalName' => $frontDesk['userPrincipalName'], 'displayName' => $frontDesk['displayName'], 'type' => 'User', 'modifiedProperties' => [],
            ]),
        ];
        $s['signIns'] = [
            ['id' => (string) Str::uuid(), 'userPrincipalName' => "breakglass01@{$domain}", 'createdDateTime' => now()->subHours(2)->toIso8601ZuluString(),
                'ipAddress' => '203.0.113.45', 'appDisplayName' => 'Azure Portal', 'location' => ['city' => 'Lagos', 'countryOrRegion' => 'NG'], 'status' => ['errorCode' => 0]],
        ];
        $s['defenderAlerts'] = [
            ['id' => 'da'.substr(md5($domain.'fwd'), 0, 20), 'title' => 'Suspicious inbox forwarding rule', 'severity' => 'medium', 'status' => 'new', 'category' => 'Exfiltration',
                'serviceSource' => 'microsoftDefenderForOffice365', 'createdDateTime' => now()->subHours(4)->toIso8601ZuluString(),
                'description' => "A forwarding rule to an external address was created on {$frontDesk['userPrincipalName']}.", 'alertWebUrl' => 'https://security.microsoft.com/alerts'],
            ['id' => 'da'.substr(md5($domain.'info'), 0, 20), 'title' => 'Informational: new device registered', 'severity' => 'informational', 'status' => 'new',
                'serviceSource' => 'azureAdIdentityProtection', 'createdDateTime' => now()->subHours(9)->toIso8601ZuluString(), 'description' => ''],
        ];
        $s['serviceIssues'] = [
            ['id' => 'EX'.(800000 + crc32($domain) % 99999), 'service' => 'Exchange Online', 'feature' => 'E-mail and calendar access', 'classification' => 'incident',
                'title' => 'Some users may be unable to access shared mailboxes in Outlook on the web', 'impactDescription' => 'Users may see an error when opening shared mailboxes. Desktop Outlook is not affected.',
                'status' => 'serviceDegradation', 'isResolved' => false, 'startDateTime' => now()->subHours(7)->toIso8601ZuluString()],
            ['id' => 'MO'.(700000 + crc32($domain) % 99999), 'service' => 'Microsoft 365 suite', 'classification' => 'advisory', 'title' => 'Admin center reports delayed',
                'impactDescription' => '', 'status' => 'serviceDegradation', 'isResolved' => false, 'startDateTime' => now()->subDay()->toIso8601ZuluString()],
        ];
        $s['monitoringSeeded'] = true;

        return $s;
    }

    /** "Simulate suspicious activity": a fresh attack-like sequence happening now. */
    public static function simulate(Tenant $tenant): void
    {
        $s = $tenant->demo_state;
        $domain = $s['domains'][0]['id'] ?? 'demo.example';
        $user = collect($s['users'])->first(fn ($u) => ($u['userType'] ?? 'Member') === 'Member' && ! str_contains($u['displayName'], 'Admin'));
        $attacker = 'support.desk@'.$domain;
        $s['audits'][] = self::audit('Set federation settings on domain', 'DirectoryManagement', $attacker, now()->subMinutes(4), [
            'displayName' => $domain, 'type' => 'Domain', 'modifiedProperties' => [['displayName' => 'IssuerUri', 'newValue' => '"http://any.sts/'.Str::random(6).'"']],
        ]);
        $s['audits'][] = self::audit('Update application – Certificates and secrets management ', 'ApplicationManagement', $attacker, now()->subMinutes(3), [
            'displayName' => 'Line of business sync', 'type' => 'Application', 'modifiedProperties' => [['displayName' => 'KeyDescription', 'newValue' => '"[KeyIdentifier=…,KeyType=Password]"']],
        ]);
        $s['audits'][] = self::audit('Admin deleted security info', 'UserManagement', $attacker, now()->subMinutes(2), [
            'userPrincipalName' => $user['userPrincipalName'] ?? "user@{$domain}", 'displayName' => $user['displayName'] ?? 'User', 'type' => 'User', 'modifiedProperties' => [],
        ]);
        $s['audits'][] = self::audit('Delete conditional access policy', 'Policy', $attacker, now()->subMinute(), [
            'displayName' => 'Block legacy authentication', 'type' => 'Policy', 'modifiedProperties' => [],
        ]);
        // A rogue app given mailbox access: one audit event per application permission, same consent.
        $corr = (string) Str::uuid();
        foreach (['Mail.ReadWrite', 'Mail.Send', 'User.Read.All'] as $role) {
            $e = self::audit('Add app role assignment to service principal', 'ApplicationManagement', $attacker, now()->subMinutes(5), [
                'displayName' => 'Microsoft Graph', 'type' => 'ServicePrincipal', 'modifiedProperties' => [
                    ['displayName' => 'AppRole.Value', 'newValue' => "\"{$role}\""],
                    ['displayName' => 'ServicePrincipal.DisplayName', 'newValue' => '"Invoice Reader"'],
                    ['displayName' => 'ServicePrincipal.AppId', 'newValue' => '"7c2f1a9e-0000-4000-8000-00000000beef"'],
                ],
            ]);
            $e['correlationId'] = $corr;
            $s['audits'][] = $e;
        }
        $tenant->demo_state = $s;
        $tenant->save();
    }

    private static function audit(string $activity, string $category, string $by, $at, array $target): array
    {
        return [
            'id' => 'Directory_'.Str::uuid(), 'category' => $category, 'activityDisplayName' => $activity, 'activityDateTime' => $at->toIso8601ZuluString(),
            'result' => 'success', 'correlationId' => (string) Str::uuid(),
            'initiatedBy' => ['user' => ['userPrincipalName' => $by]],
            'targetResources' => [['id' => (string) Str::uuid()] + $target],
        ];
    }
}
