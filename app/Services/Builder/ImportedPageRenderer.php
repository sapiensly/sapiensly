<?php

namespace App\Services\Builder;

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

    /** Cap on the returned markup — a landing brief can't spend more than this. */
    private const MAX_HTML = 120000;

    /**
     * @return array{html: string, screenshot_path: ?string}|null Null when nothing rendered.
     */
    public function render(string $html): ?array
    {
        $source = null;
        $shotPath = storage_path('app/tmp/import-shot-'.strtolower((string) Str::ulid()).'.jpg');

        try {
            $source = $this->materialise($html);

            $result = Process::timeout($this->timeoutSeconds + 20)->run([
                (string) config('services.node.binary', 'node'),
                resource_path('sandbox/page-renderer.mjs'),
                $source,
                (string) ($this->timeoutSeconds * 1000),
                $shotPath,
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

            return [
                'html' => $this->trim($rendered),
                'screenshot_path' => is_file($shotPath) ? $shotPath : null,
            ];
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
