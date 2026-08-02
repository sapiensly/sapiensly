import { onUnmounted, type Ref } from 'vue';

/**
 * Hydrates the safe, pre-built motion vocabulary a landing's bespoke `html`
 * block opts into via `data-sp-*` hooks — so a landing gets scroll reveals, a
 * staged "alive" sequence and an ambient node field WITHOUT the author ever
 * shipping JavaScript (which the LandingHtmlSanitizer strips). The author writes
 * markup + custom_css; this runtime supplies the behaviour.
 *
 * Hooks:
 *   [data-sp-reveal]                 fade + rise the element in when it scrolls
 *                                    into view (optional data-sp-reveal-delay ms).
 *   [data-sp-sequence="<ms>"]        stagger-reveal the element's DIRECT children
 *                                    one by one (the lead→agent conversation
 *                                    appearing); ms = step between children.
 *   [data-sp-motion="ambient-field"] paint an animated connected-node field
 *                                    behind the element (the orchestration motif).
 *   [data-sp-sticky-after="<id>"]    reveal this element once the element with
 *                                    that id has scrolled out of view (the
 *                                    sticky header CTA that appears past the
 *                                    hero). Hidden until then, inert while hidden.
 *   [data-sp-hide-while="<id>"]      the inverse: retreat this element upward
 *                                    while the element with that id is in view,
 *                                    and bring it back when it leaves (the fixed
 *                                    header that steps aside for the footer).
 *   [data-sp-replay]                 a control that replays a sequence: its value
 *                                    is the id of a [data-sp-sequence] container,
 *                                    or empty to replay the nearest one around it.
 *
 * These two exist because a landing ships NO author JavaScript — the sanitiser
 * strips it, and it must, since the page is public and its author is a model
 * reading untrusted input. The answer to "we need behaviour" is a vocabulary the
 * runtime implements and we review once, never an allowlist of code: HTML has a
 * finite grammar you can enumerate, JavaScript does not.
 *
 * All effects respect prefers-reduced-motion (they resolve to the final visible
 * state) and every observer / rAF / injected canvas is torn down on dispose.
 */

type Cleanup = () => void;

export function useLandingMotion(root: Ref<HTMLElement | null>) {
    const cleanups: Cleanup[] = [];
    const reduce =
        typeof window !== 'undefined' &&
        !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    function dispose(): void {
        while (cleanups.length) {
            try {
                cleanups.pop()?.();
            } catch {
                /* best-effort teardown */
            }
        }
    }

    function hydrate(): void {
        dispose();
        const el = root.value;
        if (!el || typeof window === 'undefined') {
            return;
        }
        hydrateReveal(el);
        hydrateSequence(el);
        hydrateAmbient(el);
        hydrateStickyAfter(el);
        hydrateHideWhile(el);
    }

    function hydrateReveal(el: HTMLElement): void {
        const targets = Array.from(
            el.querySelectorAll<HTMLElement>('[data-sp-reveal]'),
        );
        if (!targets.length) {
            return;
        }
        if (reduce || !('IntersectionObserver' in window)) {
            targets.forEach((t) => {
                t.style.opacity = '1';
                t.style.transform = 'none';
            });
            return;
        }
        targets.forEach((t) => {
            const delay = Number(t.getAttribute('data-sp-reveal-delay') || 0);
            t.style.opacity = '0';
            t.style.transform = 'translateY(22px)';
            t.style.transition = `opacity .7s cubic-bezier(.2,.7,.2,1) ${delay}ms, transform .7s cubic-bezier(.2,.7,.2,1) ${delay}ms`;
            t.style.willChange = 'opacity, transform';
        });
        const io = new IntersectionObserver(
            (entries) => {
                entries.forEach((e) => {
                    if (e.isIntersecting) {
                        const t = e.target as HTMLElement;
                        t.style.opacity = '1';
                        t.style.transform = 'none';
                        io.unobserve(t);
                    }
                });
            },
            { threshold: 0.14 },
        );
        targets.forEach((t) => io.observe(t));
        cleanups.push(() => io.disconnect());
    }

    /** Sequence containers, by the element, so a replay control can re-run one. */
    const replays = new Map<HTMLElement, () => void>();

    function hydrateSequence(el: HTMLElement): void {
        replays.clear();
        const containers = Array.from(
            el.querySelectorAll<HTMLElement>('[data-sp-sequence]'),
        );
        containers.forEach((c) => {
            const kids = Array.from(c.children) as HTMLElement[];
            if (!kids.length) {
                return;
            }
            const step = Number(c.getAttribute('data-sp-sequence')) || 550;
            if (reduce) {
                kids.forEach((k) => {
                    k.style.opacity = '1';
                });
                return;
            }
            kids.forEach((k) => {
                k.style.opacity = '0';
                k.style.transform = 'translateY(8px)';
                k.style.transition = 'opacity .4s ease, transform .4s ease';
            });
            const play = () => {
                kids.forEach((k, i) => {
                    // Back to the start, so a replay is a replay and not a no-op
                    // on children that are already in place.
                    k.style.opacity = '0';
                    k.style.transform = 'translateY(8px)';
                    const to = window.setTimeout(() => {
                        k.style.opacity = '1';
                        k.style.transform = 'none';
                    }, step * i);
                    cleanups.push(() => window.clearTimeout(to));
                });
            };
            replays.set(c, play);
            if ('IntersectionObserver' in window) {
                let started = false;
                const io = new IntersectionObserver(
                    (entries) => {
                        entries.forEach((e) => {
                            if (e.isIntersecting && !started) {
                                started = true;
                                play();
                                io.disconnect();
                            }
                        });
                    },
                    { threshold: 0.3 },
                );
                io.observe(c);
                cleanups.push(() => io.disconnect());
            } else {
                play();
            }
        });

        hydrateReplay(el);
    }

    /**
     * A control that replays a sequence. The original does this with a "Replay"
     * button on a debate transcript; here the author writes the button and names
     * the sequence, and the runtime owns the behaviour.
     */
    function hydrateReplay(el: HTMLElement): void {
        const controls = Array.from(
            el.querySelectorAll<HTMLElement>('[data-sp-replay]'),
        );

        controls.forEach((control) => {
            const named = control.getAttribute('data-sp-replay')?.trim();
            const target = named
                ? el.querySelector<HTMLElement>(
                      `#${CSS.escape(named)}[data-sp-sequence]`,
                  )
                : // No name: the sequence this control belongs to. Closest
                  // ancestor that holds one, then the section it sits in.
                  ((control.closest(
                      '[data-sp-sequence]',
                  ) as HTMLElement | null) ??
                  control
                      .closest('section, div')
                      ?.querySelector<HTMLElement>('[data-sp-sequence]') ??
                  null);

            const play = target ? replays.get(target) : undefined;
            if (!play) {
                // Nothing to replay: leave the control alone rather than wire a
                // click that does nothing — a dead affordance is worse than none.
                return;
            }

            const onClick = (event: Event) => {
                event.preventDefault();
                play();
            };
            control.addEventListener('click', onClick);
            cleanups.push(() => control.removeEventListener('click', onClick));
        });
    }

    /**
     * Reveal an element once another has scrolled out of view — the header CTA
     * that appears once the hero's own is gone.
     */
    function hydrateStickyAfter(el: HTMLElement): void {
        const targets = Array.from(
            el.querySelectorAll<HTMLElement>('[data-sp-sticky-after]'),
        );

        targets.forEach((target) => {
            const watchId = target.getAttribute('data-sp-sticky-after')?.trim();
            const watched = watchId
                ? el.querySelector<HTMLElement>(`#${CSS.escape(watchId)}`)
                : null;

            // Without something to watch — or without an observer — the honest
            // fallback is the visible state: an element stuck invisible is worse
            // than one that never hides.
            if (!watched || !('IntersectionObserver' in window)) {
                show(target, false);

                return;
            }

            show(target, false);
            hide(target);

            const io = new IntersectionObserver(
                ([entry]) => {
                    if (entry.isIntersecting) {
                        hide(target);
                    } else {
                        show(target, !reduce);
                    }
                },
                { threshold: 0 },
            );
            io.observe(watched);
            cleanups.push(() => io.disconnect());
        });
    }

    /**
     * Retreat an element while another is on screen — the fixed header that gets
     * out of the way once the footer arrives, and comes back the moment the
     * reader scrolls up off it.
     *
     * Deliberately skipped under prefers-reduced-motion. Every other effect here
     * resolves to its final VISIBLE state when motion is off; the equivalent for
     * a hide-on-scroll is not to hide at all. That also protects the headless
     * screenshot path (HeadlessLandingShot forces reduced motion and captures
     * full-page, so the footer is trivially "in view") — without this guard the
     * design director would be handed a landing with no header and mark it down
     * for something no visitor would ever see.
     */
    function hydrateHideWhile(el: HTMLElement): void {
        if (reduce) {
            return;
        }
        const targets = Array.from(
            el.querySelectorAll<HTMLElement>('[data-sp-hide-while]'),
        );

        targets.forEach((target) => {
            const watchId = target.getAttribute('data-sp-hide-while')?.trim();
            const watched = watchId
                ? el.querySelector<HTMLElement>(`#${CSS.escape(watchId)}`)
                : null;

            // Nothing to watch, or no observer: leave the element exactly as the
            // stylesheet drew it. The failure mode of this effect must be "the
            // header never hides", never "the header never comes back".
            if (!watched || !('IntersectionObserver' in window)) {
                return;
            }

            const io = new IntersectionObserver(
                ([entry]) => {
                    if (entry.isIntersecting) {
                        retreat(target);
                    } else {
                        settle(target);
                    }
                },
                { threshold: 0 },
            );
            io.observe(watched);
            cleanups.push(() => {
                io.disconnect();
                settle(target);
            });
        });
    }

    function retreat(el: HTMLElement): void {
        el.style.transition = 'transform .28s ease, opacity .28s ease';
        el.style.transform = 'translateY(-100%)';
        el.style.opacity = '0';
        el.style.pointerEvents = 'none';
    }

    function settle(el: HTMLElement): void {
        el.style.transition = 'transform .28s ease, opacity .28s ease';
        el.style.transform = '';
        el.style.opacity = '';
        el.style.pointerEvents = '';
    }

    function show(el: HTMLElement, animate: boolean): void {
        el.style.transition = animate
            ? 'opacity .3s ease, transform .3s ease'
            : '';
        el.style.opacity = '1';
        el.style.transform = 'none';
        el.style.pointerEvents = '';
    }

    function hide(el: HTMLElement): void {
        el.style.opacity = '0';
        el.style.transform = 'translateY(-6px)';
        // Inert while invisible: an unreachable control must not still be
        // clickable or focusable.
        el.style.pointerEvents = 'none';
    }

    function hydrateAmbient(el: HTMLElement): void {
        if (reduce) {
            return;
        }
        const hosts = Array.from(
            el.querySelectorAll<HTMLElement>(
                '[data-sp-motion="ambient-field"]',
            ),
        );
        hosts.forEach((host) => cleanups.push(ambientField(host)));
    }

    onUnmounted(dispose);

    return { hydrate, dispose };
}

/**
 * A drifting field of connected nodes painted on a canvas behind `host` — the
 * "orchestration" motif. Returns a teardown that cancels the loop, drops the
 * resize listener and removes the canvas.
 */
function ambientField(host: HTMLElement): Cleanup {
    const canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.cssText =
        'position:absolute;inset:0;z-index:0;pointer-events:none;';
    if (getComputedStyle(host).position === 'static') {
        host.style.position = 'relative';
    }
    host.insertBefore(canvas, host.firstChild);

    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return () => canvas.remove();
    }

    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    let nodes: Array<{ x: number; y: number; vx: number; vy: number }> = [];
    let w = 0;
    let h = 0;
    let raf = 0;
    let resizeTo = 0;

    const size = () => {
        w = host.offsetWidth;
        h = host.offsetHeight;
        canvas.width = w * dpr;
        canvas.height = h * dpr;
        canvas.style.width = `${w}px`;
        canvas.style.height = `${h}px`;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        const count = Math.max(24, Math.min(64, Math.round((w * h) / 22000)));
        nodes = Array.from({ length: count }, () => ({
            x: Math.random() * w,
            y: Math.random() * h,
            vx: (Math.random() - 0.5) * 0.24,
            vy: (Math.random() - 0.5) * 0.24,
        }));
    };

    const tick = () => {
        ctx.clearRect(0, 0, w, h);
        for (const n of nodes) {
            n.x += n.vx;
            n.y += n.vy;
            if (n.x < 0 || n.x > w) n.vx *= -1;
            if (n.y < 0 || n.y > h) n.vy *= -1;
        }
        for (let a = 0; a < nodes.length; a++) {
            for (let b = a + 1; b < nodes.length; b++) {
                const dx = nodes[a].x - nodes[b].x;
                const dy = nodes[a].y - nodes[b].y;
                const d = Math.hypot(dx, dy);
                if (d < 128) {
                    ctx.strokeStyle = `rgba(77,163,255,${0.15 * (1 - d / 128)})`;
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(nodes[a].x, nodes[a].y);
                    ctx.lineTo(nodes[b].x, nodes[b].y);
                    ctx.stroke();
                }
            }
        }
        for (const n of nodes) {
            ctx.fillStyle = 'rgba(150,190,255,.5)';
            ctx.beginPath();
            ctx.arc(n.x, n.y, 1.5, 0, 6.29);
            ctx.fill();
        }
        raf = requestAnimationFrame(tick);
    };

    const onResize = () => {
        window.clearTimeout(resizeTo);
        resizeTo = window.setTimeout(size, 200);
    };

    size();
    tick();
    window.addEventListener('resize', onResize);

    return () => {
        cancelAnimationFrame(raf);
        window.clearTimeout(resizeTo);
        window.removeEventListener('resize', onResize);
        canvas.remove();
    };
}
