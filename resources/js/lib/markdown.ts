/**
 * Some models emit a list-item marker alone on its line with the item's content
 * on the next line ("- \nText"). With marked's `breaks: true`, that single
 * newline becomes a `<br>`, so the marker renders as a literal "-<br>Text"
 * instead of a bullet. Joining the marker with its following content line makes
 * marked parse it as a real list item.
 *
 * Covers `-`, `*`, `+` and ordered (`1.` / `1)`) markers. A marker is only
 * joined when the next line has actual content — a marker followed by a blank
 * line is a genuinely empty item and is left untouched. Thematic breaks (`---`)
 * are unaffected (more than one marker char on the line).
 */
export function normalizeListMarkers(content: string): string {
    return content.replace(
        /^([ \t]*(?:[-*+]|\d{1,9}[.)]))[ \t]*\n(?=[ \t]*\S)/gm,
        '$1 ',
    );
}
