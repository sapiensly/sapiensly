/**
 * HTML utilities for the artifact workbench.
 *
 * TipTap edits block/inline content — it can't represent `<!doctype>`,
 * `<head>`, `<script>`, or inline `<style>`. So when we flip to Visual
 * mode we hand TipTap only the <body> contents, and on the way back we
 * splice the edited HTML back into the original document so everything
 * the user didn't touch (doctype, head, scripts, styles) survives.
 */

/**
 * Extract the inner HTML of the first `<body>…</body>` block. Returns the
 * whole string unchanged when the input doesn't look like a full document
 * (e.g. a fragment the AI returned without a body tag).
 */
export function extractBody(fullHtml: string): string {
    const match = fullHtml.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
    return match ? match[1] : fullHtml;
}

/**
 * Replace the inner HTML of the first `<body>…</body>` block with
 * `newBodyHtml`. If the original doesn't have a body tag, returns the
 * fragment on its own — callers that want a wrapped document should
 * compose one themselves.
 */
export function replaceBodyContents(
    fullHtml: string,
    newBodyHtml: string,
): string {
    if (!/<body[^>]*>[\s\S]*?<\/body>/i.test(fullHtml)) {
        return newBodyHtml;
    }
    return fullHtml.replace(
        /(<body[^>]*>)([\s\S]*?)(<\/body>)/i,
        (_, open: string, _body: string, close: string) =>
            `${open}${newBodyHtml}${close}`,
    );
}
