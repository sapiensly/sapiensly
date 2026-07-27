<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * Serves the widget JavaScript file.
 *
 * This controller serves the built widget.js file with appropriate
 * caching headers for CDN and browser caching.
 *
 * Caching strategy:
 * - Production: 1 year cache with ETag for cache busting
 * - Development: 5 minute cache for easier testing
 * - CDN: Cloudflare/Fastly compatible headers
 */
class WidgetAssetController extends Controller
{
    /**
     * Serve the widget JavaScript file.
     *
     * GET /widget/v1/widget.js
     */
    public function script(Request $request): Response
    {
        $path = public_path('widget/v1/widget.js');

        if (! File::exists($path)) {
            return response('Widget not found. Run `npm run build:widget` to build it.', 404);
        }

        $content = File::get($path);
        $lastModified = File::lastModified($path);
        $etag = '"'.md5($content).'"';

        // Handle conditional requests (If-None-Match)
        $clientEtag = $request->header('If-None-Match');
        if ($clientEtag === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', $this->getCacheControl());
        }

        // Handle conditional requests (If-Modified-Since)
        $clientModified = $request->header('If-Modified-Since');
        if ($clientModified && strtotime($clientModified) >= $lastModified) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', $this->getCacheControl());
        }

        return response($content)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', $this->getCacheControl())
            ->header('ETag', $etag)
            ->header('Last-Modified', gmdate('D, d M Y H:i:s', $lastModified).' GMT')
            ->header('Vary', 'Accept-Encoding')
            // CORS headers for cross-origin embedding
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', '*')
            // Security headers
            ->header('X-Content-Type-Options', 'nosniff')
            // CDN hints — same window as the browser, for the same reason: an
            // edge holding a year-old bundle serves it to EVERY site at once.
            ->header('CDN-Cache-Control', self::CACHE_CONTROL)
            ->header('Cloudflare-CDN-Cache-Control', self::CACHE_CONTROL);
    }

    /**
     * How long an embedding site may hold this bundle.
     *
     * This URL is UNVERSIONED — every embed snippet ever copied points at
     * `/widget/v1/widget.js` forever — so the cache window is the only thing
     * deciding when a change actually reaches the sites running it. It used to
     * be `max-age=31536000, immutable` in production, and `immutable` means the
     * browser will not even ask whether a newer file exists: a year, per visitor,
     * with no way to shorten it. A fix to the bundle simply would not arrive.
     * That is a bad property for a feature and an unacceptable one for a
     * security fix — this file carries the session token the widget proves
     * ownership with.
     *
     * So: a short window, then revalidate. `stale-while-revalidate` keeps it
     * fast — the cached copy is served instantly while a fresh one is fetched in
     * the background — and the ETag above turns the revalidation into a 304 of a
     * few bytes rather than a re-download of 150 KB.
     *
     * The real long-term answer is a content-hashed filename, which needs the
     * loader snippet to resolve the current build instead of hard-coding a path.
     * Until then, this is what makes a deploy reach anybody.
     */
    private const CACHE_CONTROL = 'public, max-age=300, stale-while-revalidate=86400';

    private function getCacheControl(): string
    {
        return self::CACHE_CONTROL;
    }
}
