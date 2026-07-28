/**
 * Renders an imported HTML document in headless Chrome and prints the DOM it
 * produced, so the importer can read a client-rendered page (a React bundle, a
 * self-extracting design export) that has no markup until its JavaScript runs.
 *
 * Driving puppeteer directly rather than through Browsershot is deliberate:
 * Browsershot refuses any document that so much as CONTAINS the string `file://`
 * and any `file://` URL outright, which a real bundle trips in its own comments.
 * Both of those guards defend against local file access — enforced here instead
 * by never giving the page a filesystem origin at all. `setContent` runs it on
 * about:blank, and `setOfflineMode` severs every request (literal IPs included,
 * which a DNS- or proxy-based block does not reliably cover).
 *
 * Usage: node page-renderer.mjs <htmlPath> <timeoutMs> [screenshotPath]
 * Prints {"html": "<rendered body>"} on stdout; errors go to stderr, exit 1.
 */
import { readFileSync } from 'node:fs';
import puppeteer from 'puppeteer';

const [, , htmlPath, timeoutArg, screenshotPath] = process.argv;

if (!htmlPath) {
    process.stderr.write('usage: page-renderer.mjs <htmlPath> <timeoutMs> [screenshotPath]');
    process.exit(1);
}

const timeout = Math.max(5000, Math.min(Number(timeoutArg) || 45000, 120000));

/** Enough visible text that we're looking at a page, not a spinner. */
const MOUNTED_TEXT_THRESHOLD = 200;

const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--force-prefers-reduced-motion'],
});

try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1400 });

    // Airgap before a single byte of the document runs.
    await page.setOfflineMode(true);

    await page.setContent(readFileSync(htmlPath, 'utf8'), {
        waitUntil: 'domcontentloaded',
        timeout,
    });

    // A bundle transpiles and mounts after DOMContentLoaded, so the interesting
    // DOM appears later. Wait for it to look mounted, but treat the timeout as a
    // result rather than a failure: a page that never fills in still has a body
    // worth reporting, and the caller would rather see it than get nothing.
    await page
        .waitForFunction(
            (min) => (document.body?.innerText ?? '').trim().length > min,
            { timeout },
            MOUNTED_TEXT_THRESHOLD,
        )
        .catch(() => {});

    // Reveal-on-scroll sections start at opacity:0 and are shown by an
    // IntersectionObserver, which never fires in a fullPage capture because the
    // viewport never moves. Without this the DOM comes back complete while the
    // screenshot is a hero, a footer and a mile of empty page in between — and a
    // design director judging that picture would be judging a lie. Walk the page
    // a viewport at a time so every observer fires, then return to the top.
    await page.evaluate(async () => {
        const settle = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
        const step = Math.floor(window.innerHeight * 0.8);

        // Long enough for the observer callback AND the transition it starts —
        // a faster walk scrolls past a section before it has finished appearing.
        for (let y = 0; y < document.body.scrollHeight; y += step) {
            window.scrollTo(0, y);
            await settle(320);
        }
        window.scrollTo(0, 0);
        await settle(400);

        // Anything still transparent never got its observer callback (a threshold
        // it never met, a one-shot that fired off-screen). For a picture meant to
        // be JUDGED, a section that exists but doesn't show is the worst outcome —
        // reveal the stragglers. Scoped to reveal-shaped elements: transparent,
        // transformed, and big enough to be content rather than a closed menu.
        document.querySelectorAll('*').forEach((el) => {
            const style = getComputedStyle(el);
            if (parseFloat(style.opacity) !== 0) return;
            if (el.getBoundingClientRect().height < 20) return;
            el.style.setProperty('opacity', '1', 'important');
            el.style.setProperty('transform', 'none', 'important');
        });
        await settle(200);
    });

    const html = await page.evaluate(() => document.body?.innerHTML ?? '');

    if (screenshotPath) {
        try {
            await page.screenshot({ path: screenshotPath, type: 'jpeg', quality: 72, fullPage: true });
        } catch {
            // The picture is optional; the markup is not.
        }
    }

    process.stdout.write(JSON.stringify({ html }));
} catch (error) {
    process.stderr.write(String(error?.message ?? error));
    process.exit(1);
} finally {
    await browser.close();
}
