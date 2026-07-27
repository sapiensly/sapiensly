<?php

namespace App\Support\Site;

use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything one fetch of an organization's website yields, as an immutable
 * value object: the visible prose the Contextbook is drafted from AND the brand
 * signals the Brandbook is drafted from. One download, two consumers — the
 * alternative is fetching the same home page twice for two features that ask
 * the same question ("who is this organization?").
 *
 * Every field is best-effort and nullable. A real home page is hostile input:
 * malformed markup, brand colours declared nowhere, logos that are inline SVG or
 * CSS backgrounds. Nothing here throws — a signal that cannot be read is simply
 * absent, and the consumer decides whether that matters.
 *
 * Reliability is NOT uniform, and consumers should treat it accordingly:
 * `text`, `title` and `iconUrl` are usually there; `themeColor` and `logoUrl`
 * are present maybe half the time; `fonts` is a hint, not a fact. Brand values
 * derived from here belong in front of a human as proposals, never applied blind.
 */
final class SiteProfile
{
    /** Sanity cap on stored prose. Consumers trim further for their own prompt budget. */
    private const MAX_TEXT_CHARS = 20000;

    /** Rel values that mark a favicon-ish link, best first. */
    private const ICON_RELS = ['apple-touch-icon', 'icon', 'shortcut icon'];

    /** Hosts whose stylesheet links name a font family in the URL. */
    private const FONT_CDN_HOSTS = ['fonts.googleapis.com', 'fonts.bunny.net'];

    /**
     * @param  list<string>  $fonts
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $text = null,
        public readonly ?string $iconUrl = null,
        public readonly ?string $logoUrl = null,
        public readonly ?string $themeColor = null,
        public readonly ?string $colorScheme = null,
        public readonly array $fonts = [],
    ) {}

    /**
     * Parse a fetched page. Returns a profile with whatever could be read; a
     * document that yields nothing at all still returns a profile (with a url
     * and nulls), so callers branch on the fields they need, not on null.
     */
    public static function parse(string $html, string $url): self
    {
        $xpath = self::xpath($html);

        if ($xpath === null) {
            return new self(url: $url, text: self::visibleText($html));
        }

        $base = self::attr($xpath, '//base/@href') ?? $url;

        return new self(
            url: $url,
            title: self::text($xpath, '//title'),
            description: self::meta($xpath, 'description') ?? self::property($xpath, 'og:description'),
            text: self::visibleText($html),
            iconUrl: self::iconUrl($xpath, $base),
            logoUrl: self::logoUrl($xpath, $base),
            themeColor: self::hex(self::meta($xpath, 'theme-color')),
            colorScheme: self::colorScheme(self::meta($xpath, 'color-scheme')),
            fonts: self::fonts($xpath, $html),
        );
    }

    /** True when the page yielded nothing worth drafting from. */
    public function isEmpty(): bool
    {
        return $this->text === null && $this->title === null
            && $this->iconUrl === null && $this->logoUrl === null && $this->themeColor === null;
    }

    /** The brand-relevant half, for a Brandbook proposal. */
    public function hasBrandSignals(): bool
    {
        return $this->iconUrl !== null || $this->logoUrl !== null
            || $this->themeColor !== null || $this->fonts !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'icon_url' => $this->iconUrl,
            'logo_url' => $this->logoUrl,
            'theme_color' => $this->themeColor,
            'color_scheme' => $this->colorScheme,
            'fonts' => $this->fonts,
        ];
    }

    /**
     * The words a reader would see. Tags become spaces rather than vanishing:
     * strip_tags alone welds adjacent blocks into "LogisticsWe move…" and hands
     * the consumer a word that does not exist.
     */
    public static function visibleText(string $html): ?string
    {
        $stripped = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $spaced = strip_tags((string) preg_replace('/<[^>]*>/', ' ', $stripped));
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($spaced, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : Str::limit($text, self::MAX_TEXT_CHARS, '');
    }

    /** A DOM query handle, or null when the markup is unparseable. */
    private static function xpath(string $html): ?DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;
            // The meta prefix pins the encoding without the deprecated
            // mb_convert_encoding(HTML-ENTITIES) dance.
            $loaded = $dom->loadHTML(
                '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
                LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET,
            );

            return $loaded ? new DOMXPath($dom) : null;
        } catch (Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function iconUrl(DOMXPath $xpath, string $base): ?string
    {
        foreach (self::ICON_RELS as $rel) {
            $href = self::attr($xpath, '//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")='
                .'"'.$rel.'"]/@href');
            if ($href !== null) {
                return self::absolute($href, $base);
            }
        }

        return null;
    }

    /**
     * og:image first (a deliberate, curated choice by whoever built the site),
     * then an <img> that calls itself a logo.
     */
    private static function logoUrl(DOMXPath $xpath, string $base): ?string
    {
        $og = self::property($xpath, 'og:image');
        if ($og !== null) {
            return self::absolute($og, $base);
        }

        foreach ($xpath->query('//img[@src]') ?: [] as $img) {
            $haystack = strtolower(
                $img->getAttribute('src').' '.$img->getAttribute('alt').' '.$img->getAttribute('class')
            );
            if (str_contains($haystack, 'logo')) {
                return self::absolute($img->getAttribute('src'), $base);
            }
        }

        return null;
    }

    /**
     * Font families, best signal first: a webfont CDN link names the family in
     * the URL, which beats guessing from a CSS stack.
     *
     * @return list<string>
     */
    private static function fonts(DOMXPath $xpath, string $html): array
    {
        $families = [];

        foreach ($xpath->query('//link[@href]') ?: [] as $link) {
            $href = $link->getAttribute('href');
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if (! in_array($host, self::FONT_CDN_HOSTS, true)) {
                continue;
            }
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            foreach (explode('|', (string) ($query['family'] ?? '')) as $spec) {
                $family = self::cleanFamily(explode(':', $spec)[0]);
                if ($family !== null) {
                    $families[] = $family;
                }
            }
        }

        // Fall back to whatever the page's own CSS declares first.
        // Quotes stay in the capture — a quoted family is the common case
        // (font-family: "Comic Sans MS", cursive) and cleanFamily strips them.
        if (preg_match_all('/font-family\s*:\s*([^;}]+)/i', $html, $matches)) {
            foreach ($matches[1] as $stack) {
                $family = self::cleanFamily(explode(',', $stack)[0]);
                if ($family !== null) {
                    $families[] = $family;
                }
            }
        }

        return array_slice(array_values(array_unique($families)), 0, 5);
    }

    private static function cleanFamily(string $value): ?string
    {
        $family = trim(str_replace(['+', '"', "'"], [' ', '', ''], $value));

        // Generic keywords and CSS variables say nothing about the brand.
        $generic = ['inherit', 'initial', 'unset', 'sans-serif', 'serif', 'monospace', 'cursive', 'fantasy', 'system-ui'];

        return $family === '' || str_starts_with($family, 'var(') || in_array(strtolower($family), $generic, true)
            ? null
            : Str::limit($family, 60, '');
    }

    private static function meta(DOMXPath $xpath, string $name): ?string
    {
        return self::attr($xpath, '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")='
            .'"'.$name.'"]/@content');
    }

    private static function property(DOMXPath $xpath, string $property): ?string
    {
        return self::attr($xpath, '//meta[@property="'.$property.'"]/@content');
    }

    private static function attr(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        $value = $nodes !== false && $nodes->length > 0 ? trim($nodes->item(0)->nodeValue ?? '') : '';

        return $value === '' ? null : $value;
    }

    private static function text(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent ?? ''));

        return $value === '' ? null : Str::limit($value, 200, '');
    }

    /** #RGB and #RRGGBB normalized to #rrggbb; anything else (named, rgb()) dropped. */
    private static function hex(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m)) {
            [$r, $g, $b] = str_split($m[1]);

            return '#'.$r.$r.$g.$g.$b.$b;
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : null;
    }

    /** 'light' or 'dark' when the page states a single preference. */
    private static function colorScheme(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['light', 'dark'], true) ? $value : null;
    }

    /**
     * Resolve a possibly-relative URL against the page, dropping anything not
     * http(s). A same-host `http://` on an `https://` page is upgraded: sites
     * routinely hard-code `http` in an `og:image` they serve over TLS anyway,
     * and the un-upgraded URL is mixed content the browser refuses to render.
     */
    private static function absolute(string $href, string $base): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with(strtolower($href), 'data:')) {
            return null;
        }

        try {
            $resolved = (string) UriResolver::resolve(new Uri($base), new Uri($href));
            $resolved = self::upgradeToBaseScheme($resolved, $base);
        } catch (Throwable) {
            return null;
        }

        $scheme = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $resolved : null;
    }

    /**
     * Promote a same-host `http://` asset to `https://` when the page itself was
     * served over TLS. Only the same host: upgrading a third-party CDN we have
     * no evidence about would just break the URL.
     */
    private static function upgradeToBaseScheme(string $resolved, string $base): string
    {
        $sameHost = strtolower((string) parse_url($resolved, PHP_URL_HOST))
            === strtolower((string) parse_url($base, PHP_URL_HOST));

        $baseIsSecure = strtolower((string) parse_url($base, PHP_URL_SCHEME)) === 'https';
        $resolvedIsPlain = strtolower((string) parse_url($resolved, PHP_URL_SCHEME)) === 'http';

        return $sameHost && $baseIsSecure && $resolvedIsPlain
            ? (string) (new Uri($resolved))->withScheme('https')
            : $resolved;
    }
}
