<?php

namespace App\Services\Dns;

class DnsResolver implements DnsResolverInterface
{
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT) ?: [];

        return array_values(array_map(
            fn ($r) => isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? ''),
            $records
        ));
    }

    public function cname(string $name): ?string
    {
        $records = @dns_get_record($name, DNS_CNAME) ?: [];

        return $records[0]['target'] ?? null;
    }
}
