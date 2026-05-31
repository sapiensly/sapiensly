<?php

namespace App\Services\Security\Ssrf;

/**
 * Resolves a hostname to its A/AAAA addresses. Injected so tests can supply a
 * deterministic fake instead of hitting the network.
 */
interface DnsResolver
{
    /**
     * @return list<string> resolved IPv4/IPv6 literals (may be empty)
     */
    public function resolve(string $host): array;
}
