/**
 * Renders an imported page in headless Chrome and prints the DOM it produced, so
 * the importer can read a client-rendered page (a React bundle, a self-extracting
 * design export, any SPA) that has no markup until its JavaScript runs.
 *
 * Two sources, two threat models:
 *
 *   A LOCAL DOCUMENT (a paste, an upload) is rendered AIRGAPPED. Driving
 *   puppeteer directly rather than through Browsershot is deliberate: Browsershot
 *   refuses any document that so much as CONTAINS the string `file://`, which a
 *   real bundle trips in its own comments. Both of its guards defend against
 *   local file access — enforced here instead by never giving the page a
 *   filesystem origin at all. `setContent` runs it on about:blank, and
 *   `setOfflineMode` severs every request (literal IPs included, which a DNS- or
 *   proxy-based block does not reliably cover).
 *
 *   A URL cannot be airgapped — an SPA that cannot fetch its own bundle renders
 *   nothing, which is the whole reason this mode exists. So the network is open
 *   and narrowed instead: the caller has already cleared the address through the
 *   SSRF guard and passes the IP it resolved to, which is pinned here with
 *   --host-resolver-rules so the page cannot be re-pointed between the check and
 *   the load; and every request the page then makes to a private, loopback or
 *   link-local address is aborted. Residual risk is a THIRD-party host that
 *   itself resolves internally — hence the pin covers the one host we vouched
 *   for, and everything else is judged on the address it asks for.
 *
 * Usage: node page-renderer.mjs <htmlPath|url> <timeoutMs> [screenshotPath] [--allow-private] [--pin=host:ip]
 * Prints {"html", "styles", "css", "fonts", "revealed"} on stdout; errors go to
 * stderr, exit 1.
 */
import { readFileSync } from 'node:fs';
import puppeteer from 'puppeteer';

const argv = process.argv.slice(2);
const flags = argv.filter((a) => a.startsWith('--'));
const [source, timeoutArg, screenshotPath] = argv.filter(
    (a) => !a.startsWith('--'),
);

if (!source) {
    process.stderr.write(
        'usage: page-renderer.mjs <htmlPath|url> <timeoutMs> [screenshotPath] [--allow-private] [--pin=host:ip]',
    );
    process.exit(1);
}

const isUrl = /^https?:\/\//i.test(source);
const allowPrivate = flags.includes('--allow-private');
const pin = (flags.find((f) => f.startsWith('--pin=')) ?? '').slice(
    '--pin='.length,
);

const timeout = Math.max(5000, Math.min(Number(timeoutArg) || 45000, 120000));

/** Enough visible text that we're looking at a page, not a spinner. */
const MOUNTED_TEXT_THRESHOLD = 200;

/** Cap on the CSS we collect off a live page, before the caller's own budget. */
const MAX_CSS = 160000;

/**
 * Addresses a page must never be able to reach from here: loopback, private and
 * link-local space, plus the hostnames that conventionally point at them. A name
 * that RESOLVES into that space is not caught by this (Chrome resolves it, we
 * only see the string) — the main document is protected from that by the pin.
 */
function isPrivateHost(hostname) {
    const host = (hostname || '').toLowerCase().replace(/^\[|\]$/g, '');
    if (host === '') return false; // data:, blob: — no host to reach.
    if (
        host === 'localhost' ||
        host.endsWith('.localhost') ||
        host.endsWith('.local') ||
        host.endsWith('.internal')
    ) {
        return true;
    }

    const v4 = host.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
    if (v4) {
        const [a, b] = [Number(v4[1]), Number(v4[2])];
        return (
            a === 0 ||
            a === 10 ||
            a === 127 ||
            a >= 224 ||
            (a === 169 && b === 254) ||
            (a === 172 && b >= 16 && b <= 31) ||
            (a === 192 && b === 168) ||
            (a === 100 && b >= 64 && b <= 127)
        );
    }

    if (host.includes(':')) {
        // IPv6 literal: loopback, unique-local (fc00::/7) and link-local.
        return host === '::1' || /^f[cd]/.test(host) || host.startsWith('fe80');
    }

    return false;
}

const browser = await puppeteer.launch({
    headless: true,
    args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--force-prefers-reduced-motion',
        // Pin the host the caller cleared to the IP it cleared, so the name
        // cannot resolve somewhere else now that we are the one connecting.
        ...(pin
            ? [
                  `--host-resolver-rules=MAP ${pin.split(':')[0]} ${pin.split(':').slice(1).join(':')}`,
              ]
            : []),
    ],
});

try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 1400 });

    if (isUrl) {
        if (!allowPrivate) {
            await page.setRequestInterception(true);
            page.on('request', (request) => {
                let hostname = '';
                try {
                    hostname = new URL(request.url()).hostname;
                } catch {
                    hostname = '';
                }
                if (isPrivateHost(hostname)) {
                    request.abort().catch(() => {});
                } else {
                    request.continue().catch(() => {});
                }
            });
        }

        // networkidle2 rather than domcontentloaded: an SPA's document is empty
        // until its bundle has been fetched AND run.
        await page
            .goto(source, { waitUntil: 'networkidle2', timeout })
            .catch(() => {});
    } else {
        // Airgap before a single byte of the document runs.
        await page.setOfflineMode(true);

        await page.setContent(readFileSync(source, 'utf8'), {
            waitUntil: 'domcontentloaded',
            timeout,
        });
    }

    // A bundle transpiles and mounts after DOMContentLoaded, so the interesting
    // DOM appears later. Wait for it to look mounted, but treat the timeout as a
    // result rather than a failure: a page that never fills in still has a body
    // worth reporting, and the caller would rather see it than get nothing.
    //
    // A URL waits far less for this than a local bundle does: `networkidle2`
    // already established that the page settled, so the only thing left is a
    // late mount. A page that is simply SHORT — a hero and a call to action —
    // never crosses the threshold, and making every one of those pay the full
    // ceiling turned a five-second import into a forty-five-second one.
    await page
        .waitForFunction(
            (min) => (document.body?.innerText ?? '').trim().length > min,
            { timeout: isUrl ? Math.min(timeout, 8000) : timeout },
            MOUNTED_TEXT_THRESHOLD,
        )
        .catch(() => {});

    // AT REST, before anything is scrolled into view: whatever is transparent
    // right now is what the page reveals as you go down it. Mark those elements
    // with the platform's own hook, so the DOM handed to the model already
    // carries `data-sp-reveal` on exactly the elements the original revealed —
    // instead of the model inferring, from a frame where everything is visible,
    // that nothing moves.
    //
    // Taken here rather than by rendering the page a second time and diffing:
    // same information, half the work, and no risk of the two runs disagreeing.
    const revealed = await page.evaluate(() => {
        let marked = 0;
        for (const el of document.querySelectorAll('*')) {
            const style = getComputedStyle(el);
            if (parseFloat(style.opacity) !== 0) continue;
            // Content, not a closed menu or a zero-box placeholder.
            const box = el.getBoundingClientRect();
            if (box.height < 20 || box.width < 20) continue;
            el.setAttribute('data-sp-reveal', '');
            marked++;
        }
        return marked;
    });

    // Reveal-on-scroll sections start at opacity:0 and are shown by an
    // IntersectionObserver, which never fires in a fullPage capture because the
    // viewport never moves. Without this the DOM comes back complete while the
    // screenshot is a hero, a footer and a mile of empty page in between — and a
    // design director judging that picture would be judging a lie. Walk the page
    // a viewport at a time so every observer fires, then return to the top.
    await page.evaluate(async () => {
        const settle = (ms) =>
            new Promise((resolve) => setTimeout(resolve, ms));
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

    // A live page keeps its design in stylesheets it FETCHED, and its images
    // behind relative paths that mean nothing once the markup is somewhere else.
    // Neither survives the trip on its own, so both are resolved here, against
    // the page's own address, while we are still standing on it.
    const collected = isUrl
        ? await page.evaluate((maxCss) => {
              // The design: every rule the page actually applies to something.
              // Shipping the whole stylesheet would be mostly a framework's
              // unused utilities — the reason a Tailwind build is 300 KB of which
              // a page wears 20 — and shipping none of it would leave the model
              // guessing a palette it can already read.
              const fonts = new Set();
              const unreadable = [];

              const matchesSomething = (selector) => {
                  try {
                      // :hover and friends never match at rest — keep them, they
                      // are exactly the interaction the copy has to reproduce.
                      const probe = selector.replace(
                          /::?(hover|focus|focus-visible|active|visited|target|before|after|placeholder|selection|first-line|first-letter|backdrop|marker)\b(\([^)]*\))?/g,
                          '',
                      );
                      return (
                          probe.trim() === '' ||
                          document.querySelector(probe) !== null
                      );
                  } catch {
                      // A selector this browser cannot parse tells us nothing —
                      // keep it rather than drop design we cannot judge.
                      return true;
                  }
              };

              // A relative url() in a fetched stylesheet resolves against THAT
              // file, not the page — so the base travels with the rules.
              const absolutise = (cssText, base) =>
                  cssText.replace(
                      /url\((['"]?)(?!https?:|data:|#)([^'")]+)\1\)/g,
                      (match, quote, target) => {
                          try {
                              return `url(${quote}${new URL(target, base).href}${quote})`;
                          } catch {
                              return match;
                          }
                      },
                  );

              const collect = (rules, base) => {
                  const parts = [];

                  for (const rule of rules) {
                      // A style rule earns its place by dressing something on
                      // screen; :root variables match the document and stay.
                      if (rule.type === CSSRule.STYLE_RULE) {
                          if (matchesSomething(rule.selectorText)) {
                              parts.push(absolutise(rule.cssText, base));
                          }
                          continue;
                      }

                      // Faces and frames travel whole: they are the vocabulary
                      // the matched rules are written in.
                      if (rule.type === CSSRule.FONT_FACE_RULE) {
                          const family =
                              rule.style?.getPropertyValue('font-family');
                          if (family)
                              fonts.add(family.replace(/['"]/g, '').trim());
                          parts.push(absolutise(rule.cssText, base));
                          continue;
                      }
                      if (rule.type === CSSRule.KEYFRAMES_RULE) {
                          parts.push(rule.cssText);
                          continue;
                      }

                      // @media / @supports / @layer: worth its wrapper only when
                      // something inside it survived the match.
                      if (rule.cssRules) {
                          const inner = collect(rule.cssRules, base);
                          if (inner !== '') {
                              parts.push(
                                  `${rule.cssText.split('{')[0].trim()}{${inner}}`,
                              );
                          }
                      }
                  }

                  return parts.join('\n');
              };

              const sheets = [];
              for (const sheet of document.styleSheets) {
                  try {
                      sheets.push(
                          collect(
                              sheet.cssRules,
                              sheet.href || document.baseURI,
                          ),
                      );
                  } catch {
                      // Cross-origin stylesheet: the browser refuses to read it.
                      // Naming it is the honest outcome — the caller can say the
                      // design came back partial instead of pretending otherwise.
                      if (sheet.href) unreadable.push(sheet.href);
                  }
              }
              const out = sheets.filter(Boolean).join('\n').slice(0, maxCss);

              // What the page is actually SET in, whatever the cascade decided.
              for (const selector of ['body', 'h1', 'h2', 'button', 'nav']) {
                  const el = document.querySelector(selector);
                  if (!el) continue;
                  const family = getComputedStyle(el).fontFamily;
                  if (family)
                      fonts.add(
                          family.split(',')[0].replace(/['"]/g, '').trim(),
                      );
              }

              // Assets, and only NOW: the reconstruction is served from another
              // origin, so a relative src resolves to a 404 there. Absolute URLs
              // keep the pictures.
              //
              // Strictly after the CSS is read, and never on a <link>. Writing an
              // href onto a stylesheet link makes Chrome re-fetch it, and while
              // it does, that sheet leaves document.styleSheets — which is how
              // this pass silently emptied the design it was standing next to.
              for (const el of document.querySelectorAll(
                  'img, source, video, audio, iframe, a, area, embed',
              )) {
                  for (const attr of ['src', 'href', 'poster']) {
                      const value = el.getAttribute(attr);
                      if (
                          !value ||
                          /^(https?:|data:|mailto:|tel:|#)/i.test(value)
                      )
                          continue;
                      try {
                          el.setAttribute(
                              attr,
                              new URL(value, document.baseURI).href,
                          );
                      } catch {
                          /* leave it as written */
                      }
                  }

                  const srcset = el.getAttribute('srcset');
                  if (srcset) {
                      el.setAttribute(
                          'srcset',
                          srcset
                              .split(',')
                              .map((part) => {
                                  const [url, ...rest] = part
                                      .trim()
                                      .split(/\s+/);
                                  if (!url || /^(https?:|data:)/i.test(url))
                                      return part.trim();
                                  try {
                                      return [
                                          new URL(url, document.baseURI).href,
                                          ...rest,
                                      ].join(' ');
                                  } catch {
                                      return part.trim();
                                  }
                              })
                              .join(', '),
                      );
                  }
              }

              return {
                  css: out,
                  fonts: [...fonts].filter(Boolean),
                  unreadable,
              };
          }, MAX_CSS)
        : { css: '', fonts: [], unreadable: [] };

    // The picture first: hoisting strips every `style=`, and the page has no
    // stylesheet to replace them with, so a capture taken afterwards is of an
    // unstyled document.
    if (screenshotPath) {
        try {
            await page.screenshot({
                path: screenshotPath,
                type: 'jpeg',
                quality: 72,
                fullPage: true,
            });
        } catch {
            // The picture is optional; the markup is not.
        }
    }

    // Hoist inline styles into deduplicated rules.
    //
    // A React export puts its look in `style=` attributes — 577 of them on the
    // page this was built for. The landing sanitiser strips every one, so as
    // markup they are dead on arrival: the model has to READ them out of the
    // prompt and re-derive a stylesheet. Converting them here turns that into
    // transcription, and collapses the repeats (every row of a list carries the
    // same declaration) so the DOM we hand over shrinks at the same time.
    const styles = await page.evaluate(() => {
        const rules = new Map();
        let n = 0;

        for (const el of document.querySelectorAll('[style]')) {
            // `!important` here is ours: the straggler pass above is the only
            // thing that writes it. Keeping it would hand the model a rule that
            // pins opacity on, which is our capture trick, not the design.
            const css = (el.getAttribute('style') || '')
                .split(';')
                .filter((d) => d.trim() && !d.includes('!important'))
                .join(';')
                .trim()
                .replace(/\s+/g, ' ');
            el.removeAttribute('style');
            if (!css) continue;

            let name = rules.get(css);
            if (!name) {
                name = `x${++n}`;
                rules.set(css, name);
            }
            el.classList.add(name);
        }

        return [...rules].map(([css, name]) => `.${name}{${css}}`).join('\n');
    });

    const html = await page.evaluate(() => document.body?.innerHTML ?? '');

    process.stdout.write(
        JSON.stringify({
            html,
            styles,
            revealed,
            css: collected.css,
            fonts: collected.fonts,
            unreadable_stylesheets: collected.unreadable,
        }),
    );
} catch (error) {
    process.stderr.write(String(error?.message ?? error));
    process.exit(1);
} finally {
    await browser.close();
}
