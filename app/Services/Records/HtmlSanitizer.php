<?php

namespace App\Services\Records;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Server-side sanitiser for rich_text field values. We never trust the HTML
 * the client posts: DOMPurify in the browser is bypassable. So whatever lands
 * here is walked tag-by-tag against a strict allowlist, attributes are
 * stripped except for safe `href` on links, and anything outside the list
 * (including <script>, <iframe>, event handlers like onclick) is dropped.
 *
 * The allowlist matches what the TipTap toolbar can produce in the MVP:
 * paragraph/break + emphasis + headings (H2, H3) + lists + links.
 */
class HtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'p', 'br',
        'strong', 'em', 'u', 'b', 'i',
        'h2', 'h3',
        'ul', 'ol', 'li',
        'a',
    ];

    /**
     * Tags that get deleted whole (along with their text content) rather than
     * unwrapped. Keeping the text of <script> or <style> would expose JS / CSS
     * snippets in the rendered output — exactly what we're trying to prevent.
     *
     * @var list<string>
     */
    private const DANGEROUS_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'math', 'noscript', 'form', 'input', 'button', 'textarea', 'select', 'option'];

    /**
     * Hierarchy of safe URL schemes for <a href>. Bare anchors (#section)
     * and protocol-relative URLs are also allowed; everything else (javascript:,
     * data:, vbscript:, file:, etc.) is stripped.
     *
     * @var list<string>
     */
    private const ALLOWED_HREF_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitize(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previousInternal = libxml_use_internal_errors(true);

        // Wrap in a UTF-8 meta + body so DOMDocument parses curly quotes etc.
        // correctly and we have a stable root to walk from.
        $wrapped = '<?xml encoding="UTF-8"?><html><body>'.$trimmed.'</body></html>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previousInternal);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->walk($body);

        // Re-serialise just the body's children.
        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Convert sanitised HTML to plain text — used to enforce max_length on
     * the actual reading-content rather than the markup overhead.
     */
    public function plainText(string $html): string
    {
        $stripped = strip_tags($html);

        return trim(html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function walk(DOMNode $node): void
    {
        // Snapshot children before mutating — replacing/removing nodes during
        // iteration over a live NodeList is fraught.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                // Text nodes, comments, etc. — drop comments, keep text.
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                // Drop the whole subtree — keeping inner text would surface
                // raw JS/CSS as plain text on the page.
                $node->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Disallowed but harmless tag: keep its text contents and
                // recurse, but drop the wrapper so paragraphs aren't erased.
                $this->unwrap($child);

                continue;
            }

            // Drop every attribute except a strictly-validated `href` on <a>.
            // Iterate over a snapshot because removeAttribute mutates the list.
            $attrNames = [];
            foreach ($child->attributes as $attr) {
                $attrNames[] = $attr->nodeName;
            }
            foreach ($attrNames as $attrName) {
                if ($tag === 'a' && strtolower($attrName) === 'href') {
                    $href = $child->getAttribute($attrName);
                    if (! $this->isSafeHref($href)) {
                        $child->removeAttribute($attrName);
                    } else {
                        // Force target=_blank + rel for external links — keeps
                        // the host app from being opener-bombed.
                        if (! str_starts_with($href, '#')) {
                            $child->setAttribute('target', '_blank');
                            $child->setAttribute('rel', 'noopener noreferrer');
                        }
                    }

                    continue;
                }
                $child->removeAttribute($attrName);
            }

            // Recurse into the (now-clean) children.
            $this->walk($child);
        }
    }

    /**
     * Replace a node with its children — used for disallowed tags so we keep
     * the text they were wrapping.
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
        // Protocol-relative (//foo.com) — block, force explicit scheme.
        if (str_starts_with($href, '//')) {
            return false;
        }
        $colon = strpos($href, ':');
        if ($colon === false) {
            return true; // relative path
        }
        $scheme = strtolower(substr($href, 0, $colon));

        return in_array($scheme, self::ALLOWED_HREF_SCHEMES, true);
    }
}
