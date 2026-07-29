<?php

namespace App\Support\Site;

use App\Services\Security\Ssrf\SafeHttpClient;

/**
 * The URL a human typed, turned into one we can actually fetch.
 *
 * People type `acme.com`. A `url` validation rule rejects that, the request
 * comes back 422, and the form says "the site could not be read" — about a site
 * that reads perfectly. Normalizing before validating is the whole difference
 * between an import that works and one the user blames their website for.
 *
 * Deliberately conservative: it adds the scheme people omit and nothing else.
 * A `javascript:` or `file:` URL is refused rather than coerced into something
 * fetchable, and the host must look like a public name — {@see SafeHttpClient}
 * is still the boundary that decides what may actually be requested.
 */
final class SiteUrl
{
    /**
     * Returns the fetchable form of what the user typed, or null when there is
     * no reading of it that could be fetched.
     */
    public static function normalize(?string $input): ?string
    {
        $url = trim((string) $input);

        if ($url === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) === 1) {
            // A scheme was typed: it is http(s) or it is not a website.
            if (preg_match('#^https?://#i', $url) !== 1) {
                return null;
            }
        } else {
            // The overwhelmingly common case: a bare host, or one with a path.
            $url = 'https://'.ltrim($url, '/');
        }

        $host = parse_url($url, PHP_URL_HOST);

        // A dotless or trailing-dot host is either an intranet name or a typo;
        // neither is the organization's public website.
        if (! is_string($host) || ! str_contains(trim($host, '.'), '.')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
    }
}
