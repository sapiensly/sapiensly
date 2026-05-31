<?php

namespace App\Services\Builder;

use App\Services\Security\Ssrf\SafeHttpClient;
use App\Services\Security\Ssrf\SsrfBlockedException;
use App\Services\Security\Ssrf\SsrfGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pulls "wireframe evidence" out of an arbitrary URL or pasted HTML so Claude
 * has something visual + textual to reconstruct as a manifest.
 *
 * The strategy is intentionally MVP-grade:
 *   - For URLs (Figma share links, claude.ai/share, websites, screenshots
 *     hosted somewhere): fetch the page and pull `og:image`, `<title>`,
 *     `og:description`, and a short text dump of the visible body.
 *   - For HTML pastes: parse directly (no network), same extraction.
 *
 * Image fetching is left to the caller — this service returns the absolute
 * URL of the og:image so the controller can do a follow-up Http::get(),
 * persist to the tenant S3 disk, and feed it to the AI job as a StoredImage.
 *
 * Hardening: SSRF protection blocks loopback / private IP ranges so users
 * can't probe internal infrastructure by submitting `http://10.0.0.1/...`.
 */
class WireframeImporter
{
    /** Cap on HTML response size to keep parsing cheap and tokens manageable. */
    private const MAX_HTML_BYTES = 512 * 1024;

    /** Cap on extracted text length passed back to the AI. */
    private const MAX_EXTRACTED_TEXT = 8000;

    /**
     * Cap on the structural HTML excerpt forwarded to the AI. 30 KB is
     * roughly 7-8K tokens — plenty for an LLM to infer layout, hierarchy
     * and Tailwind/CSS class hints without blowing the context window.
     */
    private const MAX_CLEANED_HTML = 30000;

    public function __construct(
        private SsrfGuard $ssrf,
        private SafeHttpClient $safeHttp,
    ) {}

    /**
     * @return array{image_url: ?string, title: ?string, description: ?string, text: ?string, cleaned_html: ?string, source_url: ?string}
     */
    public function fromUrl(string $url): array
    {
        $this->assertSafeUrl($url);

        try {
            // SafeHttpClient re-validates and pins the connection, and follows
            // redirects re-validating each hop (so a redirect to an internal IP
            // is refused mid-fetch, not just the initial URL).
            $response = $this->safeHttp->request('GET', $url, [
                'timeout' => 10,
                'headers' => [
                    // Some sites (Figma included) serve different OG metadata
                    // to "real" browser-looking user agents.
                    'User-Agent' => 'Mozilla/5.0 (compatible; SapienslyBuilder/1.0; +https://sapiensly.com)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
            ]);
        } catch (SsrfBlockedException $e) {
            throw new InvalidArgumentException('That URL points to a disallowed destination.');
        } catch (ConnectionException $e) {
            throw new InvalidArgumentException('Could not reach that URL: '.$e->getMessage());
        } catch (RequestException $e) {
            throw new InvalidArgumentException('The URL returned an error: '.$e->getMessage());
        }

        if (! $response->ok()) {
            throw new InvalidArgumentException("The URL returned HTTP {$response->status()}.");
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        // If the user passed a direct image URL, short-circuit. The controller
        // will download it as a normal attachment.
        if (str_starts_with($contentType, 'image/')) {
            return [
                'image_url' => $url,
                'title' => null,
                'description' => null,
                'text' => null,
                'cleaned_html' => null,
                'source_url' => $url,
            ];
        }

        if (! str_contains($contentType, 'html') && ! str_contains($contentType, 'xml')) {
            throw new InvalidArgumentException('URL does not point to an HTML page or image.');
        }

        $html = substr((string) $response->body(), 0, self::MAX_HTML_BYTES);

        return $this->extract($html, $response->effectiveUri()?->__toString() ?? $url);
    }

    /**
     * @return array{image_url: ?string, title: ?string, description: ?string, text: ?string, cleaned_html: ?string, source_url: ?string}
     */
    public function fromHtml(string $html): array
    {
        return $this->extract(substr($html, 0, self::MAX_HTML_BYTES), null);
    }

    /**
     * @return array{image_url: ?string, title: ?string, description: ?string, text: ?string, cleaned_html: ?string, source_url: ?string}
     */
    private function extract(string $html, ?string $baseUrl): array
    {
        $crawler = new Crawler($html);

        $meta = fn (string $selector): ?string => $this->firstAttr($crawler, $selector, 'content');

        $imageUrl = $meta('meta[property="og:image"]')
            ?? $meta('meta[name="og:image"]')
            ?? $meta('meta[name="twitter:image"]')
            ?? $meta('meta[property="twitter:image"]');

        if ($imageUrl !== null && $baseUrl !== null) {
            $imageUrl = $this->absoluteUrl($baseUrl, $imageUrl);
        }

        $title = $meta('meta[property="og:title"]')
            ?? $this->firstText($crawler, 'title');

        $description = $meta('meta[property="og:description"]')
            ?? $meta('meta[name="description"]');

        // Pick the most meaningful root for both the text dump and the
        // structural HTML excerpt. Falling back to <body>, then to the
        // document root, so a fragment paste (no <html>/<body>) still works.
        $rootNode = null;
        foreach (['main', 'body'] as $sel) {
            $hit = $crawler->filter($sel);
            if ($hit->count() > 0) {
                $rootNode = $hit->getNode(0);
                break;
            }
        }
        if ($rootNode === null && $crawler->count() > 0) {
            $rootNode = $crawler->getNode(0);
        }

        $text = null;
        $cleanedHtml = null;
        if ($rootNode !== null) {
            $stripped = $this->stripNoise($rootNode);
            // Plain-text dump: useful for OCR-style wireframes where the
            // structural HTML is too generic to help (e.g. a Figma-exported
            // page with thousands of empty divs).
            $rawText = preg_replace('/\s+/u', ' ', $stripped->textContent) ?? '';
            $text = trim($rawText);
            if ($text === '') {
                $text = null;
            } elseif (strlen($text) > self::MAX_EXTRACTED_TEXT) {
                $text = substr($text, 0, self::MAX_EXTRACTED_TEXT).'…';
            }

            // Structural HTML excerpt: the real magic — Claude reads tag
            // hierarchy, semantic elements (table/nav/aside/form…) and
            // Tailwind/CSS class names to infer layout, components and
            // colors. We serialize after the strip so the noise tags don't
            // leak through.
            $serialized = $stripped->ownerDocument?->saveHTML($stripped) ?? '';
            // Strip HTML comments — they're never useful and often huge in
            // exported designs ("<!-- Generator: Adobe Illustrator ..." etc).
            $serialized = preg_replace('/<!--.*?-->/s', '', $serialized) ?? $serialized;
            // Collapse runs of whitespace introduced by indented templates
            // so we keep the budget for real markup.
            $serialized = preg_replace('/\s+/', ' ', $serialized) ?? $serialized;
            $serialized = trim($serialized);
            if ($serialized !== '') {
                $cleanedHtml = strlen($serialized) > self::MAX_CLEANED_HTML
                    ? substr($serialized, 0, self::MAX_CLEANED_HTML).'…'
                    : $serialized;
            }
        }

        return [
            'image_url' => $imageUrl,
            'title' => $title,
            'description' => $description,
            'text' => $text,
            'cleaned_html' => $cleanedHtml,
            'source_url' => $baseUrl,
        ];
    }

    /**
     * Walk the node and remove tags that add noise without layout signal —
     * script/style content blows up the token budget, iframes and svgs are
     * opaque, link/meta/template don't help reproduce the layout.
     *
     * Returns the (mutated) cloned node so callers can serialize without
     * polluting the original Crawler tree.
     */
    private function stripNoise(\DOMNode $node): \DOMNode
    {
        $clone = $node->cloneNode(true);
        $noise = ['script', 'style', 'noscript', 'iframe', 'svg', 'link', 'meta', 'template'];
        if ($clone instanceof \DOMElement || $clone instanceof \DOMDocument) {
            foreach ($noise as $tag) {
                // We have to materialise the NodeList before removing because
                // getElementsByTagName is live and shifts under us.
                $matches = iterator_to_array($clone->getElementsByTagName($tag));
                foreach ($matches as $el) {
                    $el->parentNode?->removeChild($el);
                }
            }
        }

        return $clone;
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attr): ?string
    {
        $node = $crawler->filter($selector)->first();

        return $node->count() > 0 ? trim((string) $node->attr($attr)) ?: null : null;
    }

    private function firstText(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector)->first();

        return $node->count() > 0 ? trim($node->text()) ?: null : null;
    }

    /**
     * Resolve `src` against `baseUrl` per RFC 3986 — enough for the common
     * og:image cases (absolute, protocol-relative, root-relative, relative).
     */
    private function absoluteUrl(string $baseUrl, string $src): string
    {
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }
        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $src;
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($src, '//')) {
            return $parts['scheme'].':'.$src;
        }
        if (str_starts_with($src, '/')) {
            return $origin.$src;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);

        return $origin.$dir.$src;
    }

    /**
     * Refuse loopback / private / reserved destinations. Delegates to the
     * central SsrfGuard, which resolves DNS and validates the resolved IP — so
     * a hostname pointing at an internal IP is caught too (the actual fetch
     * additionally pins the connection, closing the rebinding window).
     */
    private function assertSafeUrl(string $url): void
    {
        try {
            $this->ssrf->inspect($url);
        } catch (SsrfBlockedException $e) {
            throw new InvalidArgumentException('That URL points to a disallowed destination.');
        }
    }

    /**
     * Download the image found via fromUrl() / extract() and return its raw
     * bytes. Kept here so the controller doesn't have to know about Http
     * timeouts / size caps. Returns null when the download fails — callers
     * proceed without an image attachment.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function downloadImage(string $url): ?array
    {
        try {
            $this->assertSafeUrl($url);
            $response = $this->safeHttp->request('GET', $url, [
                'timeout' => 15,
                'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; SapienslyBuilder/1.0)'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('WireframeImporter: image download failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->ok()) {
            return null;
        }
        $mime = strtolower((string) $response->header('Content-Type'));
        if (! str_starts_with($mime, 'image/')) {
            return null;
        }
        // 5 MB hard ceiling — matches the chat attachment cap.
        $bytes = (string) $response->body();
        if (strlen($bytes) > 5 * 1024 * 1024) {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }
}
