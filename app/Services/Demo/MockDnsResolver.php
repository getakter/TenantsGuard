<?php

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Services\Dns\DnsResolverInterface;

class MockDnsResolver implements DnsResolverInterface
{
    public function __construct(private readonly Tenant $tenant) {}

    public function txt(string $name): array
    {
        $dns = $this->tenant->demo_state['dns'] ?? [];
        if (str_starts_with($name, '_dmarc.')) {
            return $dns[substr($name, 7)]['dmarc'] ?? [];
        }

        return $dns[$name]['txt'] ?? [];
    }

    public function cname(string $name): ?string
    {
        if (preg_match('/^selector[12]\._domainkey\.(.+)$/', $name, $m)) {
            $dns = $this->tenant->demo_state['dns'] ?? [];

            return ($dns[$m[1]]['dkim'] ?? false) ? $name.'.dkim.mail.microsoft' : null;
        }

        return null;
    }
}
