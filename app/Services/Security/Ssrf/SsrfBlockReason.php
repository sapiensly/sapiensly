<?php

namespace App\Services\Security\Ssrf;

/**
 * Enumerated cause of an SSRF block, for logging / observability. Never expose
 * the resolved internal IP to the end user — only this category.
 */
enum SsrfBlockReason: string
{
    case Scheme = 'scheme';
    case Malformed = 'malformed';
    case Unresolved = 'unresolved';
    case BlockedIp = 'blocked_ip';
    case RedirectBlocked = 'redirect_blocked';
    case TooLarge = 'too_large';
}
