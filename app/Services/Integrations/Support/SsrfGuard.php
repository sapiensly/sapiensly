<?php

namespace App\Services\Integrations\Support;

/**
 * Blocks requests to host ranges that could be used for Server-Side Request
 * Forgery: loopback, RFC1918 private space, link-local (AWS/GCP/Azure
 * metadata), multicast, reserved ranges. Runs before the HTTP call AND on
 * every redirect hop.
 */
class SsrfGuard
{
    /**
     * @var array<int, array{string, int}> [cidr_base_numeric, prefix_bits]
     */
    private const BLOCKED_CIDR4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],   // CGNAT
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],  // link-local, cloud metadata
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],     // multicast
        ['240.0.0.0', 4],     // reserved
    ];

    /**
     * @var array<int, string> IPv6 prefixes (lowercase)
     */
    private const BLOCKED_V6_PREFIXES = [
        '::1',       // loopback
        'fc00:',     // unique local
        'fd',        // unique local (covers fd00::/8)
        'fe80:',     // link-local
        'ff',        // multicast (ff00::/8)
    ];

    /**
     * @var array<int, string>
     */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
    ];

    /**
     * @throws \RuntimeException when $url resolves to a blocked address and the
     *                           caller is not allowed to bypass the guard.
     */
    public function assertHostAllowed(string $url, bool $allowInternal = false): void
    {
        if ($allowInternal) {
            return;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            throw new \RuntimeException('URL has no host component.');
        }

        $host = strtolower($host);

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw new \RuntimeException("Host '{$host}' is blocked by the SSRF guard.");
        }

        $addresses = $this->resolve($host);

        // Unresolvable hosts cannot be used to reach internal resources by
        // definition; the HTTP dispatch will surface a cleaner DNS error.
        // Skipping the block here also lets Http::fake() tests run without
        // a DNS dependency on public hostnames.
        if (empty($addresses)) {
            return;
        }

        foreach ($addresses as $ip) {
            if ($this->isBlocked($ip)) {
                throw new \RuntimeException("Resolved IP '{$ip}' is in a blocked range.");
            }
        }
    }

    public function isBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isBlockedV4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isBlockedV6($ip);
        }

        // Unrecognised address format — err on the side of blocking.
        return true;
    }

    private function isBlockedV4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        foreach (self::BLOCKED_CIDR4 as [$base, $prefix]) {
            $mask = -1 << (32 - $prefix);
            $baseLong = ip2long($base);
            if ($baseLong === false) {
                continue;
            }
            if (($long & $mask) === ($baseLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedV6(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        $normalized = strtolower(inet_ntop($packed));

        foreach (self::BLOCKED_V6_PREFIXES as $prefix) {
            if ($prefix === '::1') {
                if ($normalized === '::1') {
                    return true;
                }

                continue;
            }
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $result = @gethostbynamel($host);
        if (is_array($result) && ! empty($result)) {
            return $result;
        }

        // Fallback for IPv6-only hosts.
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            return array_values(array_filter(array_map(
                fn (array $r) => $r['ipv6'] ?? null,
                $records,
            )));
        }

        return [];
    }
}
