<?php

namespace App\Services\Checks;

/** Built-in Microsoft Entra role template IDs (identical in every tenant). */
final class Roles
{
    public const GLOBAL_ADMIN = '62e90394-69f5-4237-9190-012177145e10';

    public const PRIVILEGED = [
        '62e90394-69f5-4237-9190-012177145e10' => 'Global Administrator',
        'e8611ab8-c189-46e8-94e1-60213ab1f814' => 'Privileged Role Administrator',
        '194ae4cb-b126-40b2-bd5b-6091b380977d' => 'Security Administrator',
        '29232cdf-9323-42fd-ade2-1d097af3e4de' => 'Exchange Administrator',
        'f28a1f50-f6e7-4571-818b-6a12f2af6b6c' => 'SharePoint Administrator',
        'fe930be7-5e62-47db-91af-98c3a49a38b1' => 'User Administrator',
        'b1be1c3e-b65d-4f19-8427-f6fa0d97feb9' => 'Conditional Access Administrator',
        '9b895d92-2cd3-44c7-9d02-a6ac2d5ea5c3' => 'Application Administrator',
        '158c047a-c907-4556-b7ef-446551a6b5f7' => 'Cloud Application Administrator',
        '729827e3-9c14-49f7-bb1b-9608f156bbb8' => 'Helpdesk Administrator',
        'b0f54661-2d74-4c50-afa3-1ec803f12efe' => 'Billing Administrator',
        'c4e39bd9-1100-46d3-8c65-fb160da0071f' => 'Authentication Administrator',
        '3a2c62db-5318-420d-8d74-23affee5d9d5' => 'Intune Administrator',
    ];

    /** Guest access levels used by authorizationPolicy.guestUserRoleId. */
    public const GUEST_SAME_AS_MEMBER = 'a0b1b346-4d3e-4e8b-98f8-753987be4970';

    public const GUEST_LIMITED = '10dae51f-b6af-4016-8d66-8c2a99b929b3';

    public const GUEST_RESTRICTED = '2af84b1e-32c8-42b7-82bc-daa82404023b';
}
