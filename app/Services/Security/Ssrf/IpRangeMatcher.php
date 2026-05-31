<?php

namespace App\Services\Security\Ssrf;

/**
 * Decides whether an IP literal falls inside a blocked range. Matching is done
 * on the binary representation (inet_pton), never on strings, so it is correct
 * for both IPv4 and IPv6. IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) are
 * unwrapped to their v4 form before matching — without that, `::ffff:169.254.169.254`
 * would bypass the metadata block.
 *
 * The blocklist lives here as constants on purpose: it must not be loosened
 * through configuration / env.
 */
class IpRangeMatcher
{
    /** @var list<string> */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this host"
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // CGNAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local — INCLUDES 169.254.169.254 (cloud metadata)
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.88.99.0/24',     // 6to4 relay anycast
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved
        '255.255.255.255/32', // broadcast
    ];

    /** @var list<string> */
    private const BLOCKED_V6 = [
        '::1/128',         // loopback
        '::/128',          // unspecified
        '::ffff:0:0/96',   // IPv4-mapped (also unwrapped + matched against v4)
        '64:ff9b::/96',    // NAT64 well-known
        '100::/64',        // discard-only
        '2001:db8::/32',   // documentation
        'fc00::/7',        // ULA (unique local)
        'fe80::/10',       // link-local
        'ff00::/8',        // multicast
    ];

    public function isBlocked(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            // Unparseable → fail closed.
            return true;
        }

        // Unwrap IPv4-mapped IPv6 (::ffff:a.b.c.d) to its 4-byte v4 form and
        // judge it purely as v4 — otherwise the ::ffff:0:0/96 v6 entry would
        // blanket-block every mapped address, public ones included.
        if (strlen($bin) === 16 && $this->isV4Mapped($bin)) {
            return $this->matchesAny(substr($bin, 12, 4), self::BLOCKED_V4);
        }

        if (strlen($bin) === 4) {
            return $this->matchesAny($bin, self::BLOCKED_V4);
        }

        return $this->matchesAny($bin, self::BLOCKED_V6);
    }

    /**
     * @param  list<string>  $cidrs
     */
    private function matchesAny(string $ipBin, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->inCidr($ipBin, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Binary v4-mapped check: first 10 bytes zero, bytes 11-12 are 0xff. */
    private function isV4Mapped(string $bin): bool
    {
        return substr($bin, 0, 10) === str_repeat("\x00", 10)
            && $bin[10] === "\xff"
            && $bin[11] === "\xff";
    }

    private function inCidr(string $ipBin, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false; // different families → this CIDR doesn't apply
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
