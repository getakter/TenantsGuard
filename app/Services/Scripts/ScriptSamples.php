<?php

namespace App\Services\Scripts;

use App\Models\Script;

/**
 * Starter library. Installed as "waiting for approval": even shipped scripts are reviewed and
 * approved by your own administrator before they can run.
 */
final class ScriptSamples
{
    public static function all(): array
    {
        return [
            [
                'name' => 'Users without MFA', 'category' => 'Identity', 'risk' => 'read',
                'description' => 'Enabled member accounts that have not registered any MFA method.',
                'parameters' => [],
                'body' => <<<'PS'
# Lists members who cannot complete an MFA challenge.
$reg = Invoke-TGGraph -Uri '/reports/authenticationMethods/userRegistrationDetails' -All
$reg | Where-Object { -not $_.isMfaRegistered -and $_.userType -eq 'member' } |
    Select-Object @{ n = 'User'; e = { $_.userDisplayName } }, @{ n = 'UPN'; e = { $_.userPrincipalName } }, @{ n = 'Admin'; e = { $_.isAdmin } }
PS,
            ],
            [
                'name' => 'Guest accounts', 'category' => 'Identity', 'risk' => 'read',
                'description' => 'All guest users with their creation date, to review external access.',
                'parameters' => [],
                'body' => <<<'PS'
$users = Invoke-TGGraph -Uri '/users?$select=displayName,userPrincipalName,userType,createdDateTime,accountEnabled' -All
$users | Where-Object { $_.userType -eq 'Guest' } |
    Select-Object displayName, userPrincipalName, accountEnabled, createdDateTime
PS,
            ],
            [
                'name' => 'Licence usage', 'category' => 'Licensing', 'risk' => 'read',
                'description' => 'Purchased, assigned and free seats per subscription.',
                'parameters' => [],
                'body' => <<<'PS'
$skus = Invoke-TGGraph -Uri '/subscribedSkus' -All
foreach ($sku in $skus) {
    [ordered]@{
        Subscription = $sku.skuPartNumber
        Purchased    = $sku.prepaidUnits.enabled
        Assigned     = $sku.consumedUnits
        Free         = $sku.prepaidUnits.enabled - $sku.consumedUnits
    }
}
PS,
            ],
            [
                'name' => 'Admin role members', 'category' => 'Security', 'risk' => 'read',
                'description' => 'Everyone holding an active Entra directory role.',
                'parameters' => [],
                'body' => <<<'PS'
$roles = Invoke-TGGraph -Uri '/directoryRoles?$expand=members' -All
foreach ($role in $roles) {
    foreach ($m in $role.members) {
        [ordered]@{ Role = $role.displayName; Member = $m.displayName; UPN = $m.userPrincipalName }
    }
}
PS,
            ],
            [
                'name' => 'Block sign-in and sign out a user', 'category' => 'Identity', 'risk' => 'change',
                'description' => 'Emergency containment for a compromised account: blocks sign-in and revokes all sessions.',
                'parameters' => [['name' => 'UserPrincipalName', 'label' => 'User (UPN)', 'type' => 'email', 'required' => true, 'help' => 'The account to contain.']],
                'body' => <<<'PS'
param(
    [Parameter(Mandatory = $true)][string]$UserPrincipalName
)
$user = Invoke-TGGraph -Uri "/users/$UserPrincipalName"
Invoke-TGGraph -Method PATCH -Uri "/users/$($user.id)" -Body @{ accountEnabled = $false } | Out-Null
Invoke-TGGraph -Method POST -Uri "/users/$($user.id)/revokeSignInSessions" | Out-Null
[ordered]@{ User = $user.displayName; UPN = $user.userPrincipalName; SignInBlocked = $true; SessionsRevoked = $true }
PS,
            ],
            [
                'name' => 'Mailboxes forwarding externally', 'category' => 'Exchange', 'risk' => 'read', 'use_exchange' => true,
                'description' => 'Mailboxes with SMTP forwarding set, a common data-exfiltration path. Needs Exchange Online app access.',
                'parameters' => [],
                'body' => <<<'PS'
Get-EXOMailbox -ResultSize Unlimited -Properties ForwardingSmtpAddress, DeliverToMailboxAndForward |
    Where-Object { $_.ForwardingSmtpAddress } |
    Select-Object DisplayName, PrimarySmtpAddress, ForwardingSmtpAddress, DeliverToMailboxAndForward
PS,
            ],
        ];
    }

    public static function install(): void
    {
        foreach (self::all() as $s) {
            if (Script::where('name', $s['name'])->exists()) {
                continue;
            }
            $script = Script::create(['name' => $s['name'], 'description' => $s['description'], 'category' => $s['category']]);
            $params = array_map(fn ($p) => $p + ['default' => null, 'options' => [], 'help' => null], $s['parameters']);
            $body = $s['body']."\n";
            $script->versions()->create([
                'version' => 1, 'body' => $body, 'sha256' => hash('sha256', $body), 'risk' => $s['risk'],
                'use_graph_module' => false, 'use_exchange' => $s['use_exchange'] ?? false, 'timeout' => 300,
                'parameters' => $params, 'lint' => ScriptLinter::lint($body, $params, $s['risk']),
                'change_note' => 'TenantsGuard sample', 'status' => 'pending', 'submitted_at' => now(),
            ]);
        }
    }
}
