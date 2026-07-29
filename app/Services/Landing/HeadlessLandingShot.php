<?php

namespace App\Services\Landing;

use App\Models\App;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Ai\Files\StoredImage;
use Spatie\Browsershot\Browsershot;

/**
 * Server-side, headless screenshot of a landing — the third source of pixels for
 * the design director, and the one that needs NO open browser tab (DraftPreviewShot)
 * and NO stored upload (LatestPreviewShot). It drives the signed landing.render
 * route in headless Chrome (Browsershot + the bundled puppeteer Chromium) and
 * captures the full page as a JPEG.
 *
 * This is what lets a FULLY headless caller — the render_landing MCP tool an
 * external agent calls, or a critique run with no builder UI attached — judge
 * (and see) real pixels instead of degrading to text-only.
 *
 * prefers-reduced-motion is forced, so every data-sp-reveal element resolves to
 * its final visible state without scrolling (see useLandingMotion), and the page
 * signals window.__spLandingReady once web fonts have loaded. Best-effort: any
 * failure (no Chromium, timeout, render error) returns null and the caller
 * degrades exactly like the other two shot sources.
 */
class HeadlessLandingShot
{
    /** Full render budget: Vue hydrate + fonts + capture. */
    public int $timeoutSeconds = 60;

    /** Viewport width; fullPage() then captures the whole scroll height. */
    public int $viewportWidth = 1440;

    public int $viewportHeight = 1400;

    /**
     * @param  string  $language  render a multilingual landing in this language
     *                            ('' = the default). The signed URL carries it,
     *                            so the shot shows the language it claims to.
     */
    public function capture(App $app, User $user, string $language = ''): ?StoredImage
    {
        try {
            // Browsershot (Chromium launch + hydrate + capture) routinely outruns
            // a web request's max_execution_time (30s in dev), which would kill the
            // MCP request mid-render. Extend it to cover the render — but only when
            // a finite limit is set (never on CLI/queue, max_execution_time=0).
            $budget = $this->timeoutSeconds + 30;
            $currentLimit = (int) ini_get('max_execution_time');
            if ($currentLimit !== 0 && $currentLimit < $budget) {
                @set_time_limit($budget);
            }

            // Signed, short-lived URL: the render route is session-less and reads
            // the tenant scope from these params (only this server can mint it).
            $url = URL::temporarySignedRoute('landing.render', now()->addMinutes(5), [
                'app' => $app->id,
                'org' => $user->organization_id,
                'uid' => $user->id,
                ...($language !== '' ? ['lang' => $language] : []),
            ]);

            $shot = Browsershot::url($url)
                ->windowSize($this->viewportWidth, $this->viewportHeight)
                ->fullPage()
                ->setScreenshotType('jpeg', 72)
                ->waitUntilNetworkIdle()
                // The page sets this once Vue mounted + document.fonts.ready.
                ->waitForFunction('window.__spLandingReady === true')
                ->timeout($this->timeoutSeconds)
                ->noSandbox()
                // Force the reduced-motion path so all reveal/sequence elements
                // are at their final visible state in a single static capture.
                ->addChromiumArguments(['force-prefers-reduced-motion'])
                ->setNodeModulePath(base_path('node_modules'));

            if (is_string($node = config('services.node.binary')) && $node !== 'node') {
                $shot->setNodeBinary($node);
            }

            $bytes = $shot->screenshot();
            if (! is_string($bytes) || $bytes === '') {
                return null;
            }

            $path = 'tmp/render-shots/'.strtolower((string) Str::ulid()).'.jpg';
            Storage::disk('local')->put($path, $bytes);

            return new StoredImage($path, 'local');
        } catch (\Throwable) {
            // Eyes are optional — a failed render must never break the caller.
            return null;
        }
    }

    /** Delete the materialised temp file once the caller has consumed it. */
    public function cleanup(?StoredImage $shot): void
    {
        if ($shot === null) {
            return;
        }
        try {
            Storage::disk('local')->delete($shot->path);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
