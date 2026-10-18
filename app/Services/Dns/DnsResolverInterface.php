<?php

namespace App\Services\Dns;

interface DnsResolverInterface
{
    /** @return string[] TXT record strings for the name. */
    public function txt(string $name): array;

    /** Returns the CNAME target, or null when there is none. */
    public function cname(string $name): ?string;
}
