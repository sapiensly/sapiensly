/**
 * Entering and leaving a pretence.
 *
 * Both switches — the demo environment and the previewed role — are the same
 * move: put a query parameter on the current url and load it again. They are
 * server-side decisions, so the server has to make them again; there is no
 * client state to flip.
 *
 * It lives here rather than in either component because two of them do it (the
 * warning bar offers the way OUT, the user menu the way IN) and because the url
 * arithmetic is the only part with a rule in it — removing the parameter for
 * "no pretence" rather than setting it to an empty string, which would leave
 * `?as_role=` on every link the page then renders.
 */

/** The url the page should go to, given the one it is on. */
export function switchedUrl(
    current: string,
    param: string,
    value: string | null,
): string {
    const url = new URL(current);

    if (value === null || value === '') {
        url.searchParams.delete(param);
    } else {
        url.searchParams.set(param, value);
    }

    return url.toString();
}

/** A full page load, because the decision is the server's. */
export function switchTo(param: string, value: string | null): void {
    window.location.href = switchedUrl(window.location.href, param, value);
}
