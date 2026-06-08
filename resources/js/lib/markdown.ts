/**
 * Repairs quirky-but-common markdown that LLMs emit and that `marked` with
 * `breaks: true` would otherwise render as literal `-` / `|` characters: stray
 * list markers and table cells whose content the model wrapped in blank lines.
 *
 * Run the output through `marked.parse(..., { breaks: true, gfm: true })`.
 */
export function normalizeChatMarkdown(content: string): string {
    return joinBlankBrokenListMarkers(repairBlankBrokenTableCells(content));
}

/**
 * Some models write a list-item marker alone on its line and put the item's
 * content on a later line, sometimes separated by blank lines
 * ("- \n\nText"). With `breaks: true` that renders as a literal "-" followed by
 * a separate paragraph. Join the marker with its following content so it parses
 * as a real list item. Covers `-`, `*`, `+` and ordered (`1.` / `1)`) markers;
 * a marker with content already on the same line, and thematic breaks (`---`),
 * are left untouched.
 */
function joinBlankBrokenListMarkers(content: string): string {
    return content.replace(
        /^([ \t]*(?:[-*+]|\d{1,9}[.)]))[ \t]*\n[ \t\r\n]*(?=\S)/gm,
        '$1 ',
    );
}

/**
 * Some models write a GFM table but wrap a cell's value in blank lines
 * ("| Label | \n\nValue\n\n |"). The blank line terminates the table, so every
 * row from there renders as literal pipe text. Collapse such a wrapped value
 * back onto its row so the whole table parses. Only matches the broken shape
 * (row, blank line(s), pipe-free value, blank line(s), a closing-pipe line) —
 * well-formed tables, whose rows are adjacent, are left untouched.
 */
function repairBlankBrokenTableCells(content: string): string {
    return content.replace(
        /(\|[^\n]*\|)[ \t]*\n(?:[ \t]*\n)+[ \t]*([^\n|]+?)[ \t]*\n(?:[ \t]*\n)+[ \t]*\|[ \t]*$/gm,
        '$1 $2 |',
    );
}
