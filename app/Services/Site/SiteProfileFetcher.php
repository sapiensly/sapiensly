<?php

namespace App\Services\Site;

use App\Services\Security\Ssrf\SafeHttpClient;
use App\Support\Site\SiteProfile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads an organization's website once and hands the result to whoever asks —
 * the Contextbook drafts from its prose, the Brandbook from its icon, logo,
 * theme colour and fonts. The memo below is the point: without it, drafting both
 * books in one request downloads the same home page twice.
 *
 * Best-effort by contract. A URL that is unreachable, hostile, or simply not
 * HTML returns null, never an exception a user has to deal with mid-form — the
 * caller degrades to "no website material" and the feature still answers.
 *
 * Every fetch goes through {@see SafeHttpClient}: the URL is tenant-supplied, so
 * it is SSRF-validated and pinned, and that applies to a logo on a CDN exactly
 * as much as to the page itself.
 */
class SiteProfileFetcher
{
    private const TIMEOUT_SECONDS = 15;

    /** @var array<string, SiteProfile|null> */
    private array $memo = [];

    public function __construct(private readonly SafeHttpClient $http) {}

    /**
     * Fetch and parse a site. Repeated calls for the same URL within one request
     * are served from the memo, including a previous failure — a site that was
     * down a moment ago is not worth a second 15-second wait in the same request.
     */
    public function fetch(?string $url): ?SiteProfile
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (array_key_exists($url, $this->memo)) {
            return $this->memo[$url];
        }

        return $this->memo[$url] = $this->load($url);
    }

    private function load(string $url): ?SiteProfile
    {
        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            ]);

            if (! $response->successful()) {
                return null;
            }

            // A PDF or an image would survive strip_tags as garbage and be handed
            // to a model as if it were prose. Only markup is worth parsing.
            $contentType = strtolower((string) $response->header('Content-Type'));
            if ($contentType !== '' && ! str_contains($contentType, 'html')) {
                return null;
            }

            $profile = SiteProfile::parse($response->body(), $url);

            return $profile->isEmpty() ? null : $profile;
        } catch (Throwable $e) {
            Log::info('SiteProfileFetcher: could not read the site', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
