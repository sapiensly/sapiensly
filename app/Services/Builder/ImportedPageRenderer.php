<?php

namespace App\Services\Builder;

use App\Services\Security\Ssrf\SsrfGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Renders an imported HTML document in headless Chrome and returns the DOM it
 * actually produced, plus a screenshot of it.
 *
 * This exists because a modern "HTML export" often contains no page. A React
 * bundle, a self-extracting design export, anything client-rendered — the markup
 * is generated at runtime, so static parsing recovers a mount point and a
 * spinner. Letting the document render itself and reading the result afterwards
 * turns "translate this JSX" back into "copy this markup", which is both an
 * easier task for the model and a far more faithful one.
 *
 * The actual rendering lives in resources/sandbox/page-renderer.mjs — see there
 * for why it drives puppeteer directly instead of Browsershot, and how the page
 * is airgapped before its JavaScript runs.
 *
 * Failure is never fatal: the caller degrades to whatever static extraction
 * recovered from the document.
 */
class ImportedPageRenderer
{
    /** Client-rendered bundles transpile in-browser; they need real time. */
    public int $timeoutSeconds = 45;

    /**
     * Cap on the returned markup. Higher than it was: hoisting the inline styles
     * out (see page-renderer.mjs) shrinks the DOM enough that a real page now
     * fits, where before the tail arrived truncated.
     */
    private const MAX_HTML = 120000;

    /**
     * How many of the hoisted per-element rules we hand to the model. NOT the storage budget: that is
     * 200,000 now (ScopedAppCss::LANDING_MAX_LENGTH) and the two were only ever
     * equal by coincidence. This one is a PROMPT cost — every extra character
     * is re-sent each turn — so it is sized by what a real page actually
     * carries (measured: 14 KB of design system, 41 KB of element rules).
     */
    private const MAX_STYLES = 60000;

    /**
     * Render a document we were handed. Airgapped — see page-renderer.mjs.
     *
     * @return array{html: string, styles: ?string, css: ?string, fonts: array<int, string>, screenshot_path: ?string}|null Null when nothing rendered.
     */
    public function render(string $html): ?array
    {
        $source = null;

        try {
            $source = $this->materialise($html);

            return $this->run($source);
        } catch (\Throwable $e) {
            Log::warning('ImportedPageRenderer: headless render errored', ['error' => $e->getMessage()]);

            return null;
        } finally {
            if ($source !== null) {
                @unlink($source);
            }
        }
    }

    /**
     * Render a live page at its own address, with the network open.
     *
     * A URL cannot be rendered airgapped the way a paste is: an SPA that cannot
     * fetch its own bundle produces the empty mount point that made this method
     * necessary in the first place. The caller MUST have cleared the URL through
     * {@see SsrfGuard} and passes the IP it resolved,
     * which is pinned in the browser so the name cannot point somewhere else by
     * the time Chrome connects. Everything the page then requests is judged on
     * the address it asks for (private space is refused) inside the renderer.
     *
     * @param  list<string>  $resolvedIps  from the SSRF guard, for the host pin
     * @return array{html: string, styles: ?string, css: ?string, fonts: array<int, string>, screenshot_path: ?string}|null
     */
    public function renderUrl(string $url, array $resolvedIps = []): ?array
    {
        try {
            $flags = [];

            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $resolvedIps !== []) {
                $flags[] = '--pin='.$host.':'.$resolvedIps[0];
            }

            // The guard being off is a deliberate environment choice (local work,
            // tests against a dev server); the renderer follows it rather than
            // blocking an address the rest of the stack just allowed.
            if (! config('security.ssrf.enabled', true)) {
                $flags[] = '--allow-private';
            }

            return $this->run($url, $flags);
        } catch (\Throwable $e) {
            Log::warning('ImportedPageRenderer: headless url render errored', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  list<string>  $flags
     * @return array{html: string, styles: ?string, css: ?string, fonts: array<int, string>, screenshot_path: ?string}|null
     */
    private function run(string $source, array $flags = []): ?array
    {
        $shotPath = storage_path('app/tmp/import-shot-'.strtolower((string) Str::ulid()).'.jpg');
        if (! is_dir(dirname($shotPath))) {
            mkdir(dirname($shotPath), 0755, true);
        }

        $result = Process::timeout($this->timeoutSeconds + 20)->run([
            (string) config('services.node.binary', 'node'),
            resource_path('sandbox/page-renderer.mjs'),
            $source,
            (string) ($this->timeoutSeconds * 1000),
            $shotPath,
            ...$flags,
        ]);

        if (! $result->successful()) {
            Log::warning('ImportedPageRenderer: headless render failed', [
                'error' => mb_substr(trim($result->errorOutput()), 0, 500),
            ]);

            return null;
        }

        $decoded = json_decode($result->output(), true);
        $rendered = is_array($decoded) ? (string) ($decoded['html'] ?? '') : '';
        if (trim($rendered) === '') {
            return null;
        }

        $styles = is_array($decoded) ? trim((string) ($decoded['styles'] ?? '')) : '';
        $css = is_array($decoded) ? trim((string) ($decoded['css'] ?? '')) : '';
        $fonts = is_array($decoded) && is_array($decoded['fonts'] ?? null)
            ? array_values(array_filter(array_map('strval', $decoded['fonts'])))
            : [];

        return [
            'html' => $this->trim($rendered),
            // The page's own inline styles, hoisted into rules the model can
            // transcribe instead of re-deriving from 500 style attributes
            // the sanitiser strips anyway.
            'styles' => $styles === '' ? null : mb_substr($styles, 0, self::MAX_STYLES),
            // The rules a LIVE page actually wears, pulled out of the stylesheets
            // it fetched — the design that static extraction can never see
            // because it lives in a separate file.
            'css' => $css === '' ? null : $this->capCss($css),
            'fonts' => $fonts,
            'screenshot_path' => is_file($shotPath) ? $shotPath : null,
        ];
    }

    /**
     * Trim collected CSS to the prompt budget, on a rule boundary.
     *
     * A real page runs well past it — the site this was built against wears
     * 149 KB of rules — and a cut in the middle of a declaration hands the model
     * a broken rule to transcribe, which it will faithfully transcribe.
     */
    private function capCss(string $css): string
    {
        if (mb_strlen($css) <= self::MAX_STYLES) {
            return $css;
        }

        $cut = mb_substr($css, 0, self::MAX_STYLES);
        $lastRule = mb_strrpos($cut, '}');

        return $lastRule === false ? '' : mb_substr($cut, 0, $lastRule + 1);
    }

    /**
     * Write the document somewhere the renderer can read it — outside the web
     * root and outside any tenant disk, deleted as soon as the render returns.
     */
    private function materialise(string $html): string
    {
        $path = storage_path('app/tmp/imported-'.strtolower((string) Str::ulid()).'.html');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $html);

        return $path;
    }

    /**
     * Drop what the page needed to build itself but the reconstruction doesn't:
     * scripts, the loader chrome it left behind, and formatting whitespace.
     */
    private function trim(string $html): string
    {
        $html = preg_replace('/<script\b.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/<div id="__bundler_[^"]*"[^>]*>.*?<\/div>/is', '', $html) ?? $html;
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;
        $html = trim($html);

        return strlen($html) > self::MAX_HTML
            ? substr($html, 0, self::MAX_HTML).'…'
            : $html;
    }

    /** Delete the screenshot once the caller has consumed it. */
    public function cleanup(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }
}
