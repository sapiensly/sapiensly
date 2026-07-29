<?php

namespace App\Services\Site;

use App\Services\Security\Ssrf\SafeHttpClient;
use App\Support\Site\SiteFetch;
use App\Support\Site\SiteProfile;
use App\Support\Site\SiteUrl;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads an organization's website once and hands the result to whoever asks —
 * the Contextbook drafts from its prose, the Brandbook from its icon, logo,
 * theme colour and fonts. The memo below is the point: without it, drafting both
 * books in one request downloads the same home page twice.
 *
 * Best-effort by contract. A URL that is unreachable, hostile, or simply not
 * HTML comes back empty, never as an exception a user has to deal with mid-form
 * — the caller degrades to "no website material" and the feature still answers.
 * {@see read()} additionally reports WHICH of those happened, for callers that
 * have to explain it to somebody.
 *
 * Every fetch goes through {@see SafeHttpClient}: the URL is tenant-supplied, so
 * it is SSRF-validated and pinned, and that applies to a logo on a CDN exactly
 * as much as to the page itself.
 */
class SiteProfileFetcher
{
    private const TIMEOUT_SECONDS = 15;

    /** @var array<string, SiteFetch> */
    private array $memo = [];

    public function __construct(private readonly SafeHttpClient $http) {}

    /**
     * Fetch and parse a site. Repeated calls for the same URL within one request
     * are served from the memo, including a previous failure — a site that was
     * down a moment ago is not worth a second 15-second wait in the same request.
     */
    public function fetch(?string $url): ?SiteProfile
    {
        return $this->read($url)->profile;
    }

    /**
     * The same fetch, but saying why it failed. Callers that put the outcome in
     * front of a human want this one: "that address is not a website" and "that
     * site did not answer" are fixed by different actions.
     *
     * The URL is normalized here, so `acme.com` reads the same site `https://acme.com`
     * does and the memo does not hold both spellings.
     */
    public function read(?string $url): SiteFetch
    {
        if (trim((string) $url) === '') {
            return SiteFetch::failed(SiteFetch::NO_URL);
        }

        $normalized = SiteUrl::normalize($url);
        if ($normalized === null) {
            return SiteFetch::failed(SiteFetch::INVALID_URL);
        }

        return $this->memo[$normalized] ??= $this->load($normalized);
    }

    private function load(string $url): SiteFetch
    {
        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            ]);

            if (! $response->successful()) {
                return SiteFetch::failed(SiteFetch::UNREACHABLE);
            }

            // A PDF or an image would survive strip_tags as garbage and be handed
            // to a model as if it were prose. Only markup is worth parsing.
            $contentType = strtolower((string) $response->header('Content-Type'));
            if ($contentType !== '' && ! str_contains($contentType, 'html')) {
                return SiteFetch::failed(SiteFetch::NOT_HTML);
            }

            $profile = SiteProfile::parse($response->body(), $url);

            return $profile->isEmpty()
                ? SiteFetch::failed(SiteFetch::EMPTY_PAGE)
                : SiteFetch::ok($profile);
        } catch (Throwable $e) {
            Log::info('SiteProfileFetcher: could not read the site', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return SiteFetch::failed(SiteFetch::UNREACHABLE);
        }
    }
}
