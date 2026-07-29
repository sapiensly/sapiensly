<?php

namespace App\Support\Landing;

use Illuminate\Http\Request;

/**
 * Which language a landing is served in, and how that is decided.
 *
 * A landing ships NO JavaScript — the sanitiser strips it, and must, since the
 * page is public and its author is a model reading untrusted input. So the page
 * cannot read `navigator.language` and cannot switch itself. The decision has to
 * happen one moment earlier, on the server, from the `Accept-Language` header
 * the browser already sends with every request.
 *
 * The order below is deliberate, and the first two rules matter more than the
 * detection:
 *
 *  1. an explicit `?lang=` — because a link someone shares in Spanish must open
 *     in Spanish regardless of the reader's browser;
 *  2. the visitor's remembered choice (cookie) — because overriding someone who
 *     already told you is worse than never asking;
 *  3. `Accept-Language` — a good proxy for a preference, not the preference
 *     itself (plenty of people run an English OS and want to read Spanish);
 *  4. the default: the FIRST entry of settings.languages.
 *
 * Anything served this way must carry `Vary: Accept-Language, Cookie`, or the
 * first visitor's language gets cached and served to everyone after them.
 */
final class LandingLanguages
{
    /** Where a visitor's explicit choice is remembered. */
    public const COOKIE = 'sp_lang';

    /** The query parameter that overrides everything and makes a link shareable. */
    public const QUERY = 'lang';

    private const COOKIE_DAYS = 365;

    /** A language tag we accept: `es`, `en`, `pt-BR`. */
    private const TAG = '/^[a-z]{2}(-[A-Za-z0-9]{2,8})?$/';

    /**
     * The languages a manifest declares, normalised. The FIRST is the default —
     * the one `content` itself is written in. An empty/absent declaration means
     * a single-language landing, which is the overwhelming majority.
     *
     * @param  array<string, mixed>|null  $settings
     * @return list<string>
     */
    public static function declared(?array $settings): array
    {
        $raw = $settings['languages'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $tag = self::normalize($tag);
            if ($tag !== null && ! in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        // One language is the same as none: there is nothing to negotiate.
        return count($out) < 2 ? [] : $out;
    }

    /**
     * The language to serve, given what the landing offers and what the request
     * asks for. Always returns one of `$available`, or '' when the landing is
     * not multilingual.
     *
     * @param  list<string>  $available
     */
    public static function resolve(Request $request, array $available): string
    {
        if ($available === []) {
            return '';
        }

        $explicit = self::match((string) $request->query(self::QUERY, ''), $available);
        if ($explicit !== null) {
            return $explicit;
        }

        $remembered = self::match((string) $request->cookie(self::COOKIE, ''), $available);
        if ($remembered !== null) {
            return $remembered;
        }

        $negotiated = self::fromAcceptLanguage((string) $request->header('Accept-Language', ''), $available);

        return $negotiated ?? $available[0];
    }

    /**
     * Whether this request chose its language explicitly — the only case where
     * the choice is worth remembering.
     */
    public static function wasExplicit(Request $request, array $available): bool
    {
        return self::match((string) $request->query(self::QUERY, ''), $available) !== null;
    }

    public static function cookieLifetimeMinutes(): int
    {
        return self::COOKIE_DAYS * 24 * 60;
    }

    /**
     * Best match for an `Accept-Language` header, honouring q-weights and
     * falling back from a region to its base language (`es-419` → `es`).
     *
     * @param  list<string>  $available
     */
    private static function fromAcceptLanguage(string $header, array $available): ?string
    {
        if (trim($header) === '') {
            return null;
        }

        $ranked = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = trim($bits[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (str_starts_with(trim($param), 'q=')) {
                    $q = (float) substr(trim($param), 2);
                }
            }
            $ranked[] = ['tag' => $tag, 'q' => $q];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);

        foreach ($ranked as $entry) {
            $hit = self::match($entry['tag'], $available);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * Resolve a requested tag against what the landing offers: exact first, then
     * base language (`pt-BR` asked, `pt` offered), then the reverse (`es` asked,
     * only `es-MX` offered).
     *
     * @param  list<string>  $available
     */
    private static function match(string $requested, array $available): ?string
    {
        $requested = self::normalize($requested);
        if ($requested === null) {
            return null;
        }

        foreach ($available as $tag) {
            if (strcasecmp($tag, $requested) === 0) {
                return $tag;
            }
        }

        $base = strtolower(explode('-', $requested)[0]);
        foreach ($available as $tag) {
            if (strtolower(explode('-', $tag)[0]) === $base) {
                return $tag;
            }
        }

        return null;
    }

    private static function normalize(string $tag): ?string
    {
        $tag = trim($tag);
        if ($tag === '' || preg_match(self::TAG, $tag) !== 1) {
            return null;
        }

        return $tag;
    }

    /**
     * Swap each html block's `content` for the requested language and remove the
     * variants map. Shared by every surface that renders a landing, so the
     * public page, the headless shot and the authenticated runtime resolve a
     * translation the same way — a surface that forgets to call this shows the
     * default language and looks like the feature is broken. Anything untranslated falls back to `content`, so a partial
     * translation degrades to the default language instead of to a blank
     * section.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function apply(array $blocks, string $language): array
    {
        foreach ($blocks as &$block) {
            if (! is_array($block)) {
                continue;
            }
            $variants = is_array($block['content_i18n'] ?? null) ? $block['content_i18n'] : null;
            if ($variants !== null) {
                if ($language !== '' && is_string($variants[$language] ?? null)) {
                    $block['content'] = $variants[$language];
                }
                unset($block['content_i18n']);
            }
            foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                if (is_array($block[$key] ?? null)) {
                    $block[$key] = self::apply($block[$key], $language);
                }
            }
            foreach (['tabs', 'sections'] as $key) {
                if (is_array($block[$key] ?? null)) {
                    foreach ($block[$key] as &$sub) {
                        if (is_array($sub) && is_array($sub['blocks'] ?? null)) {
                            $sub['blocks'] = self::apply($sub['blocks'], $language);
                        }
                    }
                    unset($sub);
                }
            }
        }
        unset($block);

        return $blocks;
    }
}
