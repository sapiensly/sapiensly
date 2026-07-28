<?php

namespace App\Support\Landing;

/**
 * Decides whether imported HTML is a DESIGNED PAGE (a landing) rather than a
 * wireframe of a data app — the deterministic counterpart to {@see LandingIntent},
 * which reads a user's words. Here there are no words to read: the user drops a
 * file and the framing has to come from the artifact itself.
 *
 * This matters because the two imports want OPPOSITE manifests. The app path
 * asks for objects + table/kanban/stat blocks; those are exactly the generic
 * blocks ManifestValidator rejects on a landing surface (`generic_block_on_landing`).
 * Guess wrong and the import either builds a CRUD skeleton for a marketing page
 * or fails validation outright.
 *
 * The rule: a self-contained document that brings its OWN styling is something
 * a designer authored (a Claude Design export, a Framer/Webflow save, a
 * one-pager). A fragment, or a document whose substance is a data grid, is a
 * wireframe of an app. Data-app evidence wins on a tie — misrouting a landing
 * costs a rebuild, misrouting an app costs a rejected manifest.
 */
final class LandingArtifact
{
    /** Below this, an embedded stylesheet is boilerplate (a reset), not a design. */
    private const MIN_DESIGN_CSS = 500;

    /** Sections a marketing page has and a CRUD screen does not. */
    private const MARKETING = '/\b(hero|pricing|precios|testimonial|testimonio|faq|call-?to-?action|\bcta\b|waitlist|newsletter|subscribe|suscri|features?|caracteristicas|footer|landing|manifesto|roadmap|use-?cases?|casos-?de-?uso)\b/i';

    /** Structures that only exist to show rows of records. */
    private const APPISH = '/<(table|thead|tbody)\b|\b(data-?grid|kanban|crud|sidebar-?nav|pagination|paginacion)\b/i';

    /**
     * True when this HTML should be rebuilt as a landing.
     */
    public static function isLandingHtml(?string $html): bool
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return false;
        }

        // A fragment is a snippet of a screen, not a page someone designed.
        if (! self::isStandaloneDocument($html)) {
            return false;
        }

        $signals = 0;

        if (self::embeddedCssLength($html) >= self::MIN_DESIGN_CSS) {
            $signals++;
        }
        if (preg_match(self::MARKETING, $html) === 1) {
            $signals++;
        }
        // A <meta property="og:*"> set is a page meant to be SHARED — nobody
        // writes Open Graph tags for an internal dashboard mockup.
        if (preg_match('/<meta[^>]+property=["\']og:/i', $html) === 1) {
            $signals++;
        }

        if ($signals === 0) {
            return false;
        }

        // One data-grid inside a marketing page (a comparison table) shouldn't
        // flip the verdict; a page BUILT on them should. Require the appish
        // evidence to be repeated before it outweighs a single design signal.
        $appish = preg_match_all(self::APPISH, $html);

        return $appish >= 2 ? $signals >= 3 : true;
    }

    /**
     * Total length of CSS the document carries inline, across every <style>.
     */
    public static function embeddedCssLength(string $html): int
    {
        if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $html, $m) < 1) {
            return 0;
        }

        return array_sum(array_map('strlen', $m[1]));
    }

    /**
     * A full document, not a pasted fragment.
     */
    private static function isStandaloneDocument(string $html): bool
    {
        return preg_match('/<!doctype\s+html|<html[\s>]/i', $html) === 1;
    }
}
