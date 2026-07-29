<?php

namespace App\Support\Html;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Landing-grade HTML sanitiser: the trust boundary for the bespoke section
 * markup a landing's author (a person, or the builder model on their behalf)
 * emits. Unlike the rich_text HtmlSanitizer — which allows only a handful of
 * inline tags and strips every attribute — this one permits the STRUCTURE a
 * landing needs (section/div/span/headings/media/lists/tables) and, crucially,
 * `class`/`id` so authored `custom_css` can target it. Styling stays in
 * custom_css; this markup carries no inline `style`, no `<script>`/`<style>`,
 * no event handlers, and no `javascript:`/`data:text` URLs.
 *
 * Security model: the markup is AUTHOR content (like custom_css), not visitor
 * input — but it is the boundary against a prompt-injected model, so the
 * allowlist is strict and closed. Everything is walked tag-by-tag; a tag off
 * the allowlist is either dropped whole (dangerous) or unwrapped (harmless);
 * every attribute is dropped unless explicitly permitted. Motion is opt-in via
 * `data-sp-*` hooks the runtime hydrates — never author JavaScript.
 */
class LandingHtmlSanitizer
{
    /**
     * Structural, textual and media tags a landing section composes from.
     *
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        // structure
        'section', 'div', 'span', 'header', 'footer', 'nav', 'main', 'article', 'aside', 'figure', 'figcaption', 'hr',
        // headings
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        // text
        'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'small', 'mark', 'sub', 'sup',
        'blockquote', 'q', 'cite', 'code', 'pre', 'abbr', 'time', 'address', 'label',
        // lists
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        // links, media, actions
        'a', 'img', 'picture', 'source', 'button',
        // native disclosure — the ONLY honest accordion on a page that allows
        // no JS: <details>/<summary> toggle in pure HTML. A decorative +/− that
        // does nothing is a lying affordance (observed in live FAQ sections).
        'details', 'summary',
        // tables
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    ];

    /**
     * Tags deleted WHOLE (with their text): keeping the inner text of <script>
     * or <style> would surface JS/CSS on the page — the exact thing we prevent.
     * math is a foreign-content XSS vector, so it goes too. form controls are
     * dropped — the lead form is a first-class block.
     *
     * @var list<string>
     */
    private const DANGEROUS_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base',
        'form', 'input', 'textarea', 'select', 'option', 'optgroup', 'fieldset', 'legend',
        'math', 'noscript', 'template', 'frame', 'frameset', 'applet',
        'audio', 'video', 'track', 'source-media', 'portal', 'marquee', 'canvas',
    ];

    /**
     * The SVG subset an ICON is made of. `svg` used to be banned outright as a
     * foreign-content vector, which made an authored icon impossible: a design
     * import came back with every icon slot an empty box, because inline SVG is
     * how every icon set (and every hand-drawn logo) ships.
     *
     * What made svg dangerous is its CHILDREN, not the element: <script>,
     * <style> and <foreignObject> re-open HTML inside it, <use href> and <a>
     * dereference URLs, and <animate> can script attribute values. None of them
     * are here, and inside an svg subtree anything off this list is dropped
     * WHOLE rather than unwrapped — text has no meaning in a shape tree, and
     * unwrapping is exactly how a smuggled payload would surface.
     *
     * Comments are stripped everywhere (see walk), which also closes the mXSS
     * family that needs a comment or CDATA inside foreign content to survive
     * re-parsing in the browser.
     *
     * @var list<string>
     */
    private const SVG_TAGS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
        'defs', 'lineargradient', 'radialgradient', 'stop', 'title', 'desc', 'text', 'tspan',
    ];

    /**
     * Geometry and presentation only. No `href`/`xlink:href` (the <use> and <a>
     * dereference surface), no `style`, no `on*`, no `filter`/`mask`/`clip-path`
     * — a paint reference is enough for an icon, and `fill`/`stroke` values are
     * additionally validated (see isSafePaint).
     *
     * DOMDocument lowercases names in HTML mode; browsers' HTML5 parser maps
     * `viewbox` back to `viewBox` and `lineargradient` to `linearGradient`, so
     * the lowercase forms here are the correct ones to match and to emit.
     *
     * @var list<string>
     */
    private const SVG_ATTRS = [
        'viewbox', 'xmlns', 'width', 'height', 'preserveaspectratio', 'focusable',
        'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit',
        'fill-rule', 'clip-rule', 'fill-opacity', 'stroke-opacity', 'opacity',
        'd', 'points', 'transform', 'vector-effect', 'shape-rendering',
        'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'dx', 'dy',
        'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform',
        'text-anchor', 'dominant-baseline', 'font-size', 'font-weight', 'font-family',
    ];

    /**
     * Attributes safe on any allowed tag. Styling lives in custom_css (targeted
     * via class/id), never inline — so `style` is never here.
     *
     * @var list<string>
     */
    private const GLOBAL_ATTRS = ['class', 'id', 'title', 'role', 'lang', 'dir'];

    /** @var list<string> */
    private const ALLOWED_HREF_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitize(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previousInternal = libxml_use_internal_errors(true);

        $wrapped = '<?xml encoding="UTF-8"?><html><body>'.$trimmed.'</body></html>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previousInternal);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->walk($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * @param  bool  $inSvg  Inside an <svg> subtree, where the allowlist is the
     *                       shape vocabulary and anything else is dropped whole.
     */
    private function walk(DOMNode $node, bool $inSvg = false): void
    {
        // Snapshot before mutating — replacing/removing during live iteration is fraught.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                $node->removeChild($child);

                continue;
            }

            if ($inSvg || $tag === 'svg') {
                // Closed vocabulary: no unwrapping, so a smuggled <p>/<style>
                // subtree leaves nothing behind instead of surfacing its text.
                if (! in_array($tag, self::SVG_TAGS, true)) {
                    $node->removeChild($child);

                    continue;
                }

                $this->filterSvgAttributes($child);
                $this->walk($child, true);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Disallowed but harmless: keep the text, drop the wrapper.
                $this->unwrap($child);

                continue;
            }

            $this->filterAttributes($child, $tag);
            $this->walk($child);
        }
    }

    /**
     * Geometry/presentation allowlist for a node inside an <svg>.
     */
    private function filterSvgAttributes(DOMElement $el): void
    {
        $names = [];
        foreach ($el->attributes as $attr) {
            $names[] = $attr->nodeName;
        }

        foreach ($names as $name) {
            $lower = strtolower($name);

            // A paint can name a reference — keep it local. `url(#id)` points at
            // a gradient in this same subtree; anything else is a fetch we don't
            // want a landing making on a visitor's behalf.
            if (in_array($lower, ['fill', 'stroke', 'stop-color'], true)) {
                if (! $this->isSafePaint($el->getAttribute($name))) {
                    $el->removeAttribute($name);
                }

                continue;
            }

            if (in_array($lower, self::SVG_ATTRS, true)
                || in_array($lower, ['class', 'id'], true)
                || str_starts_with($lower, 'aria-')
                || str_starts_with($lower, 'data-sp-')) {
                continue;
            }

            // href / xlink:href / style / on* / everything else.
            $el->removeAttribute($name);
        }
    }

    /**
     * A colour, a keyword, a CSS variable, or a same-document `url(#id)`.
     */
    private function isSafePaint(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/^url\(\s*#[\w:.-]+\s*\)$/i', $value) === 1) {
            return true;
        }

        // No scheme, no function call other than the colour ones — that rules
        // out url(http…), image-set(), and anything with a payload in it.
        return preg_match('/^(#[0-9a-f]{3,8}|[a-z-]+|(rgb|rgba|hsl|hsla|var)\([^()]*\))$/i', $value) === 1;
    }

    /**
     * Drop every attribute except an explicit allowlist; coerce the risky ones.
     */
    private function filterAttributes(DOMElement $el, string $tag): void
    {
        $names = [];
        foreach ($el->attributes as $attr) {
            $names[] = $attr->nodeName;
        }

        foreach ($names as $name) {
            $lower = strtolower($name);

            // Never: event handlers or inline styles.
            if (str_starts_with($lower, 'on') || $lower === 'style') {
                $el->removeAttribute($name);

                continue;
            }

            // <a href>: safe schemes only; external links get target + hardened rel.
            if ($tag === 'a' && $lower === 'href') {
                $href = trim($el->getAttribute($name));
                if ($this->isSafeHref($href)) {
                    if ($href !== '' && ! str_starts_with($href, '#')) {
                        $el->setAttribute('target', '_blank');
                        $el->setAttribute('rel', 'noopener noreferrer nofollow');
                    }
                } else {
                    $el->removeAttribute($name);
                }

                continue;
            }

            // Image source: safe schemes / raster data URIs only (never data:image/svg).
            if (in_array($tag, ['img', 'source'], true) && $lower === 'src') {
                if (! $this->isSafeImageSrc($el->getAttribute($name))) {
                    $el->removeAttribute($name);
                }

                continue;
            }

            // Harmless presentational media attributes.
            if (in_array($tag, ['img', 'source'], true)
                && in_array($lower, ['alt', 'width', 'height', 'loading', 'decoding', 'sizes'], true)) {
                continue;
            }

            // <details open>: render an entry expanded by default (e.g. the
            // first FAQ item). Boolean, no scripting surface.
            if ($tag === 'details' && $lower === 'open') {
                continue;
            }

            // Global safe attrs + ARIA + our own motion/data hooks.
            if (in_array($lower, self::GLOBAL_ATTRS, true)
                || str_starts_with($lower, 'aria-')
                || str_starts_with($lower, 'data-sp-')) {
                continue;
            }

            // Everything else — authored target/rel, arbitrary data-*, srcset, etc.
            $el->removeAttribute($name);
        }

        // A button must never submit or carry behaviour — force it inert.
        if ($tag === 'button') {
            $el->setAttribute('type', 'button');
        }
    }

    /**
     * Replace a node with its children — used for disallowed-but-harmless tags
     * so their text survives.
     */
    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    private function isSafeHref(string $href): bool
    {
        return self::isSafeLinkTarget($href);
    }

    /**
     * Where a link may point. Public because the fine-tune link editor validates
     * a destination BEFORE writing it, and a second opinion on this question
     * could only ever disagree with the boundary that actually enforces it.
     */
    public static function isSafeLinkTarget(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }
        if (str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return true;
        }
        if (str_starts_with($href, '//')) {
            return false; // force an explicit scheme
        }
        $colon = strpos($href, ':');
        if ($colon === false) {
            return true; // relative path
        }

        return in_array(strtolower(substr($href, 0, $colon)), self::ALLOWED_HREF_SCHEMES, true);
    }

    private function isSafeImageSrc(string $src): bool
    {
        $src = trim($src);
        if ($src === '') {
            return false;
        }
        if (str_starts_with($src, '#') || str_starts_with($src, '/')) {
            return true;
        }
        if (str_starts_with($src, '//')) {
            return false;
        }
        // Raster data URIs only — NEVER data:image/svg+xml (script vector) or data:text/*.
        if (preg_match('#^data:image/(png|jpe?g|gif|webp|avif);base64,#i', $src) === 1) {
            return true;
        }
        $colon = strpos($src, ':');
        if ($colon === false) {
            return true;
        }

        return in_array(strtolower(substr($src, 0, $colon)), ['http', 'https'], true);
    }
}
