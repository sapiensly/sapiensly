<?php

namespace App\Services\Security\Ssrf;

/**
 * Real DNS resolver backed by PHP's stub resolver. Returns every A and AAAA
 * record so the guard can reject a host where ANY address is internal (a
 * round-robin rebinding vector).
 */
class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        // dns_get_record has no per-call timeout argument; PHP's resolver is
        // governed by the system resolv.conf. We query A and AAAA together.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
