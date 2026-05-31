<?php

namespace App\Services\Security\Ssrf;

use RuntimeException;

/**
 * Raised when the SSRF guard refuses a destination. Carries an enumerated
 * `reason` for logging; the public message is intentionally generic so it never
 * leaks the resolved internal IP/host to the caller's end user.
 */
class SsrfBlockedException extends RuntimeException
{
    public function __construct(
        public readonly SsrfBlockReason $reason,
        /** Internal-only detail for logs; NOT surfaced to end users. */
        public readonly ?string $internalDetail = null,
    ) {
        parent::__construct('Outbound request blocked by the SSRF guard ('.$reason->value.').');
    }

    public static function scheme(string $detail): self
    {
        return new self(SsrfBlockReason::Scheme, $detail);
    }

    public static function malformed(string $detail): self
    {
        return new self(SsrfBlockReason::Malformed, $detail);
    }

    public static function unresolved(string $detail): self
    {
        return new self(SsrfBlockReason::Unresolved, $detail);
    }

    public static function blockedIp(string $detail): self
    {
        return new self(SsrfBlockReason::BlockedIp, $detail);
    }

    public static function redirectBlocked(string $detail): self
    {
        return new self(SsrfBlockReason::RedirectBlocked, $detail);
    }

    public static function tooLarge(string $detail): self
    {
        return new self(SsrfBlockReason::TooLarge, $detail);
    }
}
