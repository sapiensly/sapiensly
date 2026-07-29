<?php

namespace App\Services\Branding;

use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Turns a third-party SVG logo into a PNG we own.
 *
 * SVG is the normal logo format on the modern web, so refusing it outright meant
 * refusing the logo of most sites we read — and the refusal existed for a good
 * reason: an SVG is a document, it can carry scripts and external references,
 * and brand assets are served back from our own origin by an unauthenticated
 * route. Storing somebody else's SVG is storing somebody else's code.
 *
 * Rasterizing resolves both at once: the SVG is rendered once, here, and what
 * lands in storage is a PNG this server produced. **The SVG is never stored and
 * never served.**
 *
 * The render itself is safe by construction rather than by sanitizing:
 * the SVG goes in as a `data:` URI inside an `<img>`, and a browser renders an
 * SVG referenced that way in *secure static mode* — scripts do not execute and
 * external references are not fetched. That is a guarantee of the image
 * pipeline, not a filter we have to keep ahead of. JavaScript is disabled on the
 * wrapper page as well, so nothing on either side of the boundary can run.
 */
class SvgRasterizer
{
    /** Chromium launch + paint. A logo is a trivial document; this is generous. */
    public int $timeoutSeconds = 20;

    /**
     * Cap on the rendered logo, in CSS pixels, before the 2× scale factor. Wide
     * enough for a header logo at retina density, bounded so a document
     * declaring absurd dimensions cannot produce a hundred-megapixel PNG.
     */
    private const MAX_WIDTH = 1024;

    private const MAX_HEIGHT = 512;

    /**
     * Render SVG source to PNG bytes.
     *
     * @throws BrandAssetImportFailed when it cannot be rendered
     */
    public function toPng(string $svg): string
    {
        try {
            // Chromium can outrun a web request's max_execution_time (30s in
            // dev); extend it to cover the render, but only when a finite limit
            // is set (never on CLI/queue, where it is 0).
            $budget = $this->timeoutSeconds + 20;
            $currentLimit = (int) ini_get('max_execution_time');
            if ($currentLimit !== 0 && $currentLimit < $budget) {
                @set_time_limit($budget);
            }

            $shot = Browsershot::html($this->page($svg))
                // Belt and braces: the wrapper page has no scripts of its own,
                // and an <img>-referenced SVG cannot run any regardless.
                ->disableJavascript()
                ->select('img')
                ->transparentBackground()
                ->setScreenshotType('png')
                ->deviceScaleFactor(2)
                ->windowSize(self::MAX_WIDTH, self::MAX_HEIGHT)
                ->timeout($this->timeoutSeconds)
                ->noSandbox()
                ->setNodeModulePath(base_path('node_modules'));

            if (is_string($node = config('services.node.binary')) && $node !== 'node') {
                $shot->setNodeBinary($node);
            }

            $png = $shot->screenshot();
        } catch (Throwable $e) {
            Log::info('SvgRasterizer: could not render the SVG', ['error' => $e->getMessage()]);

            throw new BrandAssetImportFailed(__('That logo could not be converted. Upload the image instead.'));
        }

        if (! is_string($png) || $png === '') {
            throw new BrandAssetImportFailed(__('That logo could not be converted. Upload the image instead.'));
        }

        return $png;
    }

    /**
     * The wrapper page. The SVG travels as a `data:` URI precisely so it is
     * rendered as an image and not as a document — see the class docblock.
     */
    private function page(string $svg): string
    {
        $src = 'data:image/svg+xml;base64,'.base64_encode($svg);
        $maxWidth = self::MAX_WIDTH;
        $maxHeight = self::MAX_HEIGHT;

        return <<<HTML
            <!doctype html>
            <meta charset="utf-8">
            <style>
                html, body { margin: 0; padding: 0; background: transparent; }
                img { display: block; max-width: {$maxWidth}px; max-height: {$maxHeight}px; }
            </style>
            <img src="{$src}" alt="">
            HTML;
    }
}
