<?php

namespace App\Services\Landing;

/**
 * Thin wrapper over PHP's DNS lookups so domain verification is testable —
 * tests bind a fake instance instead of hitting real resolvers.
 */
class DnsResolver
{
    /** The CNAME target a hostname points at, or null when none resolves. */
    public function cname(string $hostname): ?string
    {
        $records = @dns_get_record($hostname, DNS_CNAME) ?: [];
        foreach ($records as $record) {
            $target = trim((string) ($record['target'] ?? ''), '. ');
            if ($target !== '') {
                return strtolower($target);
            }
        }

        return null;
    }
}
