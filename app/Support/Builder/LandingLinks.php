<?php

namespace App\Support\Builder;

use App\Support\Html\LandingHtmlSanitizer;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The link inventory of a landing — every control in its bespoke `html` blocks
 * that is supposed to take a visitor somewhere, grouped by where it currently
 * goes.
 *
 * Grouping is the whole point. A real rebuilt landing carried NINE `<a>`s to
 * `#waitlist` (header, hero, the four pricing cards, enterprise, the closing
 * CTA) — "change the primary CTA" is one intention, and editing it nine times
 * is nine chances to leave the page inconsistent. Because the grouping is
 * DERIVED from the markup, it works on landings that already exist and on
 * whatever the model writes next; nothing has to be declared up front.
 *
 * It also surfaces the two failures nobody sees until a visitor clicks:
 *  - `href="#"` — a placeholder the model leaves behind (ten of them in the
 *    same landing's footer), which reads as a link and goes nowhere;
 *  - `<button>` — the sanitiser forces it inert (`type="button"`, no handlers),
 *    so a CTA authored as a button is dead by construction. Retargeting one
 *    converts it to the `<a>` it always meant to be.
 *
 * A link is addressed by "{block_id}:{ordinal}", the ordinal being its position
 * among the block's link controls in document order — the same "locate it in
 * the STORED string" approach the in-place text and style edits use.
 */
final class LandingLinks
{
    /** Controls that are meant to take a visitor somewhere. */
    private const LINK_XPATH = '//a|//button';

    /** Enough of the label to recognise the control, not enough to bloat the panel. */
    private const MAX_LABEL = 70;

    /**
     * Every link in the manifest, grouped by its current destination.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<array{
     *     target: string,
     *     kind: string,
     *     count: int,
     *     inert_count: int,
     *     links: list<array{id: string, block_id: string, label: string, element: string}>
     * }>
     */
    public static function groups(array $manifest): array
    {
        $groups = [];

        foreach (self::htmlBlocks($manifest) as $blockId => $content) {
            [, $xpath] = self::parse($content);
            $ordinal = 0;

            foreach ($xpath->query(self::LINK_XPATH) ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $element = strtolower($node->nodeName);
                $target = $element === 'a' ? self::normalizeTarget($node->getAttribute('href')) : '';

                $groups[$target] ??= [
                    'target' => $target,
                    'kind' => self::kind($target),
                    'count' => 0,
                    'inert_count' => 0,
                    'links' => [],
                ];
                $groups[$target]['count']++;
                if ($element === 'button') {
                    $groups[$target]['inert_count']++;
                }
                $groups[$target]['links'][] = [
                    'id' => $blockId.':'.$ordinal,
                    'block_id' => $blockId,
                    'label' => self::label($node),
                    'element' => $element,
                ];

                $ordinal++;
            }
        }

        $groups = array_values($groups);

        // Broken first — the whole reason to look at this list is to find the
        // links that go nowhere — then the biggest groups, which are the ones
        // worth changing in bulk.
        usort($groups, function (array $a, array $b): int {
            $broken = ($b['kind'] === 'none' ? 1 : 0) <=> ($a['kind'] === 'none' ? 1 : 0);

            return $broken !== 0 ? $broken : $b['count'] <=> $a['count'];
        });

        return $groups;
    }

    /**
     * The in-page anchors a link can point at: every `id` in the html blocks,
     * plus the lead-form slot, which is where a landing's CTAs usually go.
     *
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public static function anchors(array $manifest): array
    {
        $ids = [];

        foreach (self::htmlBlocks($manifest) as $content) {
            [, $xpath] = self::parse($content);
            foreach ($xpath->query('//*[@id]') ?: [] as $node) {
                if ($node instanceof DOMElement && ($id = trim($node->getAttribute('id'))) !== '') {
                    $ids[$id] = true;
                }
            }
            foreach ($xpath->query('//*[@data-sp-slot="lead_form"]') ?: [] as $node) {
                if ($node instanceof DOMElement && ($id = trim($node->getAttribute('id'))) !== '') {
                    $ids[$id] = true;
                }
            }
        }

        $out = array_keys($ids);
        sort($out);

        return array_map(static fn (string $id): string => '#'.$id, $out);
    }

    /**
     * Point the given ordinals of one block's content at `$to`.
     *
     * A `<button>` becomes the `<a>` it was pretending to be — same classes, id,
     * hooks and children, so the design is untouched and the control finally
     * does something. `type` is dropped: it means nothing on an anchor.
     *
     * Reports how many it actually moved, so a caller naming the change to the
     * user cannot claim more than it did when an ordinal has gone stale.
     *
     * @param  list<int>  $ordinals
     * @return array{content: string, changed: int}
     */
    public static function retarget(string $content, array $ordinals, string $to): array
    {
        [$doc, $xpath] = self::parse($content);

        $nodes = [];
        foreach ($xpath->query(self::LINK_XPATH) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        $changed = 0;
        foreach ($ordinals as $ordinal) {
            $node = $nodes[$ordinal] ?? null;
            if (! $node instanceof DOMElement) {
                continue;
            }

            if (strtolower($node->nodeName) === 'button') {
                $node = self::toAnchor($doc, $node);
            }

            $node->setAttribute('href', $to);
            $changed++;
        }

        return ['content' => self::serialize($doc), 'changed' => $changed];
    }

    /**
     * Where a link may point. Deliberately the sanitiser's own rule — the
     * markup passes through it on save either way, so a second opinion here
     * could only ever be wrong in one direction.
     */
    public static function isValidTarget(string $target): bool
    {
        $target = trim($target);

        // A bare '#' is the placeholder we are trying to eliminate, not a
        // destination — accepting it would let the panel "fix" a dead link
        // into the same dead link.
        if ($target === '' || $target === '#') {
            return false;
        }

        return LandingHtmlSanitizer::isSafeLinkTarget($target);
    }

    /**
     * Replace a button with an equivalent anchor, in place.
     */
    private static function toAnchor(DOMDocument $doc, DOMElement $button): DOMElement
    {
        $anchor = $doc->createElement('a');

        foreach ($button->attributes as $attr) {
            if (strtolower($attr->nodeName) !== 'type') {
                $anchor->setAttribute($attr->nodeName, $attr->nodeValue ?? '');
            }
        }
        while ($button->firstChild !== null) {
            $anchor->appendChild($button->firstChild);
        }

        $button->parentNode?->replaceChild($anchor, $button);

        return $anchor;
    }

    /**
     * `href` reduced to what the panel groups on: a placeholder is no target.
     */
    private static function normalizeTarget(string $href): string
    {
        $href = trim($href);

        return $href === '#' ? '' : $href;
    }

    private static function kind(string $target): string
    {
        if ($target === '') {
            return 'none';
        }
        if (str_starts_with($target, '#')) {
            return 'anchor';
        }
        $lower = strtolower($target);
        if (str_starts_with($lower, 'mailto:')) {
            return 'email';
        }
        if (str_starts_with($lower, 'tel:')) {
            return 'phone';
        }
        if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
            return 'external';
        }

        return 'internal';
    }

    private static function label(DOMElement $node): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
        if ($text === '') {
            $text = trim($node->getAttribute('aria-label')) ?: trim($node->getAttribute('title'));
        }
        if ($text === '') {
            return '';
        }

        return mb_strlen($text) > self::MAX_LABEL
            ? mb_substr($text, 0, self::MAX_LABEL - 1).'…'
            : $text;
    }

    /**
     * Every html block in the manifest, keyed by block id.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    private static function htmlBlocks(array $manifest): array
    {
        $out = [];

        $walk = function (array $blocks) use (&$walk, &$out): void {
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? null) === 'html'
                    && is_string($block['id'] ?? null)
                    && is_string($block['content'] ?? null)) {
                    $out[$block['id']] = $block['content'];
                }
                foreach (['blocks', 'left_blocks', 'right_blocks'] as $key) {
                    if (is_array($block[$key] ?? null)) {
                        $walk($block[$key]);
                    }
                }
                foreach (['tabs', 'sections'] as $key) {
                    foreach ($block[$key] ?? [] as $sub) {
                        if (is_array($sub['blocks'] ?? null)) {
                            $walk($sub['blocks']);
                        }
                    }
                }
            }
        };

        foreach ($manifest['pages'] ?? [] as $page) {
            $walk($page['blocks'] ?? []);
        }

        return $out;
    }

    /**
     * @return array{0: DOMDocument, 1: DOMXPath}
     */
    private static function parse(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previousInternal = libxml_use_internal_errors(true);

        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><html><body>'.$html.'</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousInternal);

        return [$doc, new DOMXPath($doc)];
    }

    private static function serialize(DOMDocument $doc): string
    {
        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }
}
