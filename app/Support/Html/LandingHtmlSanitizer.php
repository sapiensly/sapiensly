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
        // tables
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
    ];

    /**
     * Tags deleted WHOLE (with their text): keeping the inner text of <script>
     * or <style> would surface JS/CSS on the page — the exact thing we prevent.
     * svg/math are foreign-content XSS vectors (e.g. <svg><script>), so they go
     * too. form controls are dropped — the lead form is a first-class block.
     *
     * @var list<string>
     */
    private const DANGEROUS_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base',
        'form', 'input', 'textarea', 'select', 'option', 'optgroup', 'fieldset', 'legend',
        'svg', 'math', 'noscript', 'template', 'frame', 'frameset', 'applet',
        'audio', 'video', 'track', 'source-media', 'portal', 'marquee', 'canvas',
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

    private function walk(DOMNode $node): void
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
