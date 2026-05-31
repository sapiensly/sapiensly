<?php

namespace App\Services\Security\Ssrf;

/**
 * Central SSRF guard. inspect() validates a URL and returns the set of
 * resolved-and-cleared IPs to connect to. It is fail-closed: any ambiguous or
 * unparseable input is blocked.
 *
 * The decisive property: we validate the IP the request will ACTUALLY connect
 * to (resolved here, then pinned by SafeHttpClient), not the string the caller
 * wrote nor a one-off lookup that could differ at connect time.
 */
class SsrfGuard
{
    /**
     * @param  list<string>  $hostAllowlist  exact internal hosts that skip the
     *                                       IP block (still resolved + pinned)
     */
    public function __construct(
        private DnsResolver $dns,
        private IpRangeMatcher $matcher,
        private array $hostAllowlist = [],
    ) {}

    public function inspect(string $url): ValidatedTarget
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw SsrfBlockedException::malformed('parse_url failed or missing scheme/host');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw SsrfBlockedException::scheme($scheme);
        }

        $rawHost = $parts['host'];
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        // parse_url keeps the brackets on IPv6 literals; strip them.
        $host = $rawHost;
        $isBracketedV6 = false;
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
            $isBracketedV6 = true;
        }

        $ips = $this->resolveTargetIps($host, $isBracketedV6);
        if ($ips === []) {
            throw SsrfBlockedException::unresolved($host);
        }

        // An allowlisted host (exact, case-insensitive) skips the IP block —
        // but we still resolved it so the connection can be pinned.
        if (! $this->isAllowlisted($host)) {
            foreach ($ips as $ip) {
                if ($this->matcher->isBlocked($ip)) {
                    // One blocked IP poisons the whole host (round-robin rebinding).
                    throw SsrfBlockedException::blockedIp($host.' -> '.$ip);
                }
            }
        }

        return new ValidatedTarget($scheme, $host, $port, $ips);
    }

    /**
     * Determine the IPs to validate: a literal IP is used as-is; a hostname is
     * resolved via DNS. Deceptive numeric encodings (decimal/hex/octal/short)
     * are rejected before they can reach the resolver — curl would interpret
     * them as IPs, but a real hostname never looks like that.
     *
     * @return list<string>
     */
    private function resolveTargetIps(string $host, bool $isBracketedV6): array
    {
        // Bracketed → must be a valid IPv6 literal.
        if ($isBracketedV6) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw SsrfBlockedException::malformed('invalid bracketed IPv6: '.$host);
            }

            return [$host];
        }

        // Standard dotted-quad / full IPv6 literal.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $this->rejectDeceptiveNumericHost($host);

        // Genuine hostname → resolve all A/AAAA records.
        return $this->dns->resolve($host);
    }

    /**
     * Reject hosts that are numeric IP encodings curl would dial but that are
     * not standard dotted-quad/IPv6 literals: pure decimal (2130706433),
     * hex (0x7f000001), octal / leading-zero (017700000001), and short forms
     * (127.1). These never appear as legitimate hostnames.
     */
    private function rejectDeceptiveNumericHost(string $host): void
    {
        if (preg_match('/^\d+$/', $host)) {
            throw SsrfBlockedException::malformed('integer-encoded IP: '.$host);
        }
        if (preg_match('/^0x[0-9a-f]+$/i', $host)) {
            throw SsrfBlockedException::malformed('hex-encoded IP: '.$host);
        }
        // All digits and dots but NOT a valid dotted-quad (FILTER_VALIDATE_IP
        // already failed above): short forms like 127.1 and octal octets.
        if (preg_match('/^[0-9.]+$/', $host)) {
            throw SsrfBlockedException::malformed('non-standard dotted IP: '.$host);
        }
        // Mixed hex octets e.g. 0x7f.0.0.1.
        if (preg_match('/^[0-9a-fx.]+$/i', $host) && stripos($host, '0x') !== false) {
            throw SsrfBlockedException::malformed('hex-octet IP: '.$host);
        }
    }

    private function isAllowlisted(string $host): bool
    {
        foreach ($this->hostAllowlist as $entry) {
            if (strcasecmp(trim((string) $entry), $host) === 0) {
                return true;
            }
        }

        return false;
    }
}
