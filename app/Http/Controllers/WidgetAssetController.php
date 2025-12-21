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
            // CDN hints
            ->header('CDN-Cache-Control', 'public, max-age=31536000')
            ->header('Cloudflare-CDN-Cache-Control', 'public, max-age=31536000');
    }

    /**
     * Get the appropriate Cache-Control header value.
     */
    private function getCacheControl(): string
    {
        if (app()->environment('production')) {
            // 1 year cache in production (rely on ETag for cache busting)
            return 'public, max-age=31536000, immutable';
        }

        // 5 minutes in development for easier testing
        return 'public, max-age=300';
    }
}
