<?php

namespace App\Services\Security\Ssrf;

/**
 * Immutable result of SsrfGuard::inspect(): the destination after validation.
 * `ips` are the resolved-and-cleared addresses; SafeHttpClient pins the
 * connection to one of them so curl never re-resolves the hostname (the step
 * that would reopen the DNS-rebinding window).
 */
final readonly class ValidatedTarget
{
    /**
     * @param  list<string>  $ips  validated IP literals (v4 and/or v6)
     */
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public array $ips,
    ) {}

    /** The IP curl should connect to. */
    public function pinnedIp(): string
    {
        return $this->ips[0];
    }

    /** curl CURLOPT_RESOLVE directive that pins host:port to the validated IP. */
    public function resolveDirective(): string
    {
        return "{$this->host}:{$this->port}:{$this->pinnedIp()}";
    }
}
