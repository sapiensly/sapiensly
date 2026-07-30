<?php

namespace App\Services\Builder;

use App\Services\Security\Ssrf\SafeHttpClient;
use App\Services\Security\Ssrf\SsrfBlockedException;
use App\Services\Security\Ssrf\SsrfGuard;
use App\Support\Builder\WireframeImportMode;
use App\Support\Landing\BundledDesign;
use App\Support\Landing\BundledMotion;
use App\Support\Landing\LandingArtifact;
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
    /**
     * Cap on HTML input size, above the 5 MB upload limit so a legitimate file
     * is never cut. Truncation happens BEFORE anything is parsed, and a partial
     * document is worse than a big one: a self-extracting bundle keeps its
     * template past the 3 MB mark, so a tighter cap silently threw the design
     * away and left the loader shell behind — observed on a real 3.47 MB export.
     */
    private const MAX_HTML_BYTES = 8 * 1024 * 1024;

    /** Cap on extracted text length passed back to the AI. */
    private const MAX_EXTRACTED_TEXT = 8000;

    /**
     * Cap on the structural HTML excerpt forwarded to the AI. 30 KB is
     * roughly 7-8K tokens — plenty for an LLM to infer layout, hierarchy
     * and Tailwind/CSS class hints without blowing the context window.
     */
    private const MAX_CLEANED_HTML = 30000;

    /**
     * A landing import is held to a different standard: the goal is to
     * REPRODUCE the page, not to infer an app from it, so the model needs the
     * markup it is copying rather than a representative sample of it.
     */
    private const MAX_CLEANED_HTML_LANDING = 80000;

    /**
     * How much of a bundle's design system we hand to the model. NOT the storage budget: that is
     * 200,000 now (ScopedAppCss::LANDING_MAX_LENGTH) and the two were only ever
     * equal by coincidence. This one is a PROMPT cost — every extra character
     * is re-sent each turn — so it is sized by what a real page actually
     * carries (measured: 14 KB of design system, 41 KB of element rules).
     */
    private const MAX_STYLESHEET = 60000;

    public function __construct(
        private SsrfGuard $ssrf,
        private SafeHttpClient $safeHttp,
        private ImportedPageRenderer $renderer,
    ) {}

    /**
     * @return array{image_url: ?string, title: ?string, description: ?string, text: ?string, cleaned_html: ?string, screenshot_path: ?string, fonts: array<int, string>, motion: ?string, element_styles: ?string, stylesheet: ?string, is_landing: bool, source_url: ?string}
     */
    public function fromUrl(string $url, WireframeImportMode $mode = WireframeImportMode::Auto): array
    {
        $resolvedIps = $this->assertSafeUrl($url);

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
                'screenshot_path' => null,
                'fonts' => [],
                'motion' => null,
                'element_styles' => null,
                'stylesheet' => null,
                'is_landing' => false,
                'source_url' => $url,
            ];
        }

        if (! str_contains($contentType, 'html') && ! str_contains($contentType, 'xml')) {
            throw new InvalidArgumentException('URL does not point to an HTML page or image.');
        }

        $html = substr((string) $response->body(), 0, self::MAX_HTML_BYTES);

        return $this->extract(
            $html,
            $response->effectiveUri()?->__toString() ?? $url,
            $mode,
            $resolvedIps,
        );
    }

    /**
     * @return array{image_url: ?string, title: ?string, description: ?string, text: ?string, cleaned_html: ?string, screenshot_path: ?string, fonts: array<int, string>, motion: ?string, element_styles: ?string, stylesheet: ?string, is_landing: bool, source_url: ?string}
     */
    public function fromHtml(string $html, WireframeImportMode $mode = WireframeImportMode::Auto): array
    {
        return $this->extract(substr($html, 0, self::MAX_HTML_BYTES), null, $mode);
    }

    /**
     * @param  list<string>  $resolvedIps  cleared IPs for `$baseUrl`, for the render pin
     * @return array{image_url: ?string, title: ?string, description: ?string, text: ?string, cleaned_html: ?string, screenshot_path: ?string, fonts: array<int, string>, motion: ?string, element_styles: ?string, stylesheet: ?string, is_landing: bool, source_url: ?string}
     */
    private function extract(
        string $html,
        ?string $baseUrl,
        WireframeImportMode $mode = WireframeImportMode::Auto,
        array $resolvedIps = [],
    ): array {
        // A self-extracting bundle has no page in it — the loader shell would
        // parse as "This page requires JavaScript to display". Recover the real
        // document (head, SEO, design tokens) and parse THAT instead; the markup
        // comes from a headless render further down, the only place it exists.
        $bundle = BundledDesign::isBundle($html) ? BundledDesign::unpack($html) : null;
        $parseTarget = $bundle['document'] ?? $html;

        // Decide what KIND of artifact this is: a designed page and an app
        // wireframe want different evidence out of the same parse (see
        // LandingArtifact). A design bundle is a designed page by construction.
        // The user's chosen mode overrides the guess — on a URL the guess is made
        // before the evidence exists, which is how a marketing page rendered in
        // the browser used to look like an unstyled fragment.
        $detectedLanding = $bundle !== null || LandingArtifact::isLandingHtml($html);
        $isLanding = $mode->wantsDesignEvidence($detectedLanding);

        $crawler = new Crawler($parseTarget);

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
            $stripped = $this->stripNoise($rootNode, keepIcons: $isLanding);
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
            $budget = $isLanding ? self::MAX_CLEANED_HTML_LANDING : self::MAX_CLEANED_HTML;
            if ($serialized !== '') {
                $cleanedHtml = strlen($serialized) > $budget
                    ? substr($serialized, 0, $budget).'…'
                    : $serialized;
            }
        }

        // Client-rendered documents have no markup until their JavaScript runs.
        // A bundle always needs the render; so does anything whose static body
        // came back nearly empty while the document is full of scripts — that is
        // an SPA, and parsing it statically yields a mount point.
        //
        // This is NOT gated on the artifact being a designed page any more. The
        // gate was the bug: an app you want to model on a React site parses to
        // `<div id="root"></div>` exactly like a marketing one does, so the
        // import arrived with no evidence at all and the model, given nothing,
        // answered that it cannot browse the web.
        $screenshotPath = null;
        $hoistedStyles = null;
        $renderedCss = null;
        $renderedFonts = [];
        // A reproduction of a LIVE page always renders, even one whose markup
        // parsed perfectly: its design is in stylesheets it fetched and its
        // proof is a picture, and static extraction has neither.
        $mustSeeIt = $baseUrl !== null && $mode === WireframeImportMode::Replica;

        if ($bundle !== null || $mustSeeIt || $this->looksClientRendered($html, $text)) {
            // A live URL has to render at its own address: its bundle, styles and
            // images are fetched relative to it, and a copy of the document on
            // our disk resolves none of them.
            $rendered = $baseUrl !== null
                ? $this->renderer->renderUrl($baseUrl, $resolvedIps)
                : $this->renderer->render($html);

            if ($rendered !== null) {
                // A reproduction gets the whole DOM (the renderer's own ceiling);
                // inferring an app from it does not need — or want to pay for —
                // more markup than the structural budget.
                $cleanedHtml = $isLanding
                    ? $rendered['html']
                    : $this->cap($rendered['html'], false);
                $screenshotPath = $rendered['screenshot_path'];
                $hoistedStyles = $rendered['styles'];
                $renderedCss = $rendered['css'] ?? null;
                $renderedFonts = $rendered['fonts'] ?? [];

                // The text dump was taken from the shell; the page it stood in
                // for exists now, so re-take it from what actually rendered.
                $text = $this->textOf($cleanedHtml) ?? $text;

                // And now the artifact can finally be judged: everything the
                // detector reads (sections, copy, styling) only existed after
                // the browser built it.
                if ($mode === WireframeImportMode::Auto) {
                    $detectedLanding = $detectedLanding || LandingArtifact::isLandingHtml(
                        '<!doctype html><html><head><style>'.((string) $renderedCss).'</style></head><body>'.$cleanedHtml.'</body></html>'
                    );
                    $isLanding = $detectedLanding;
                }
            }
        }

        return [
            'image_url' => $imageUrl,
            'title' => $title,
            'description' => $description,
            'text' => $text,
            'cleaned_html' => $cleanedHtml,
            'screenshot_path' => $screenshotPath,
            'fonts' => $bundle['fonts'] ?? $renderedFonts,
            // The rendered DOM is one frame; the movement lives in the sources.
            'motion' => $bundle !== null ? BundledMotion::brief($html) : null,
            // The page's own per-element styles, already deduplicated into
            // rules. Separate from the design system: one is the vocabulary,
            // this is how each element actually uses it.
            'element_styles' => $hoistedStyles,
            // stripNoise() drops <style> along with the rest of the noise, which
            // is right when inferring an app's STRUCTURE from a mockup and wrong
            // when reproducing a design: there the stylesheet IS the artifact —
            // palette, type scale, spacing, motion. Hand it over separately so
            // the model rebuilds the look instead of inventing one.
            'stylesheet' => $bundle !== null
                ? $bundle['stylesheet']
                // A live page keeps its design in stylesheets it fetched, so the
                // rules come back from the render (already narrowed to what the
                // page actually wears); a pasted document carries its own inline.
                : ($isLanding ? ($renderedCss ?? $this->extractStylesheet($html)) : null),
            'is_landing' => $isLanding,
            'source_url' => $baseUrl,
        ];
    }

    /** Trim rendered markup to the budget this kind of import is allowed. */
    private function cap(string $html, bool $isLanding): string
    {
        $budget = $isLanding ? self::MAX_CLEANED_HTML_LANDING : self::MAX_CLEANED_HTML;

        return strlen($html) > $budget ? substr($html, 0, $budget).'…' : $html;
    }

    /** The visible words of a markup string, for the plain-text fallback. */
    private function textOf(string $html): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
        if ($text === '') {
            return null;
        }

        return strlen($text) > self::MAX_EXTRACTED_TEXT
            ? substr($text, 0, self::MAX_EXTRACTED_TEXT).'…'
            : $text;
    }

    /**
     * A document that builds itself in the browser: scripts present, but the
     * static parse recovered barely any text. Parsing that yields a mount point
     * and whatever the loader says while it works.
     */
    private function looksClientRendered(string $html, ?string $text): bool
    {
        return mb_strlen(trim((string) $text)) < 400
            && preg_match('/<script\b/i', $html) === 1;
    }

    /**
     * Concatenate every <style> block in document order, stripped of comments
     * and collapsed, capped at the landing custom_css budget.
     */
    private function extractStylesheet(string $html): ?string
    {
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $m) < 1) {
            return null;
        }

        $css = implode("\n", $m[1]);
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;
        $css = preg_replace('/\n\s*\n+/', "\n", $css) ?? $css;
        $css = trim($css);

        if ($css === '') {
            return null;
        }

        return strlen($css) > self::MAX_STYLESHEET
            ? substr($css, 0, self::MAX_STYLESHEET).'…'
            : $css;
    }

    /**
     * Walk the node and remove tags that add noise without layout signal —
     * script/style content blows up the token budget, iframes and svgs are
     * opaque, link/meta/template don't help reproduce the layout.
     *
     * Returns the (mutated) cloned node so callers can serialize without
     * polluting the original Crawler tree.
     */
    private function stripNoise(\DOMNode $node, bool $keepIcons = false): \DOMNode
    {
        $clone = $node->cloneNode(true);
        $noise = ['script', 'style', 'noscript', 'iframe', 'link', 'meta', 'template'];

        // An svg is noise in a mockup (a Figma export is thousands of them) and
        // is the DESIGN in a page being reproduced — it is how every icon and
        // logo is drawn, and the landing sanitiser now keeps them.
        if (! $keepIcons) {
            $noise[] = 'svg';
        }
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
     *
     * Returns the cleared IPs so a headless render of the same URL can pin the
     * host to them instead of resolving the name a second time.
     *
     * @return list<string>
     */
    private function assertSafeUrl(string $url): array
    {
        // Same operational kill-switch SafeHttpClient honours, so an environment
        // that deliberately turned the guard off (a dev box importing from a
        // local server) is not half-blocked here and allowed one line later.
        if (! config('security.ssrf.enabled', true)) {
            return [];
        }

        try {
            return $this->ssrf->inspect($url)->ips;
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
