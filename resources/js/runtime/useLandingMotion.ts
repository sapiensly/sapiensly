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

    function hydrateSequence(el: HTMLElement): void {
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
                    const to = window.setTimeout(() => {
                        k.style.opacity = '1';
                        k.style.transform = 'none';
                    }, step * i);
                    cleanups.push(() => window.clearTimeout(to));
                });
            };
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
