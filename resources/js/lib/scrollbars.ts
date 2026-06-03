/**
 * Auto-hiding scrollbars: reveal the thumb only while an element is being
 * scrolled, then fade it back out after a short idle delay. Pairs with the
 * `.is-scrolling` styles in resources/css/app.css. Hover/focus reveal is
 * handled by CSS alone; this only covers the "while scrolling" case, which
 * CSS cannot detect on its own.
 */

const IDLE_MS = 900;
const CLASS = 'is-scrolling';

const timers = new WeakMap<Element, ReturnType<typeof setTimeout>>();

function flag(target: EventTarget | null): void {
    const el =
        target === document || target instanceof Document
            ? document.documentElement
            : target instanceof Element
              ? target
              : null;

    if (!el) {
        return;
    }

    el.classList.add(CLASS);

    const existing = timers.get(el);
    if (existing) {
        clearTimeout(existing);
    }

    timers.set(
        el,
        setTimeout(() => {
            el.classList.remove(CLASS);
            timers.delete(el);
        }, IDLE_MS),
    );
}

function isApplePlatform(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }
    const platform =
        (navigator as Navigator & { userAgentData?: { platform?: string } })
            .userAgentData?.platform ||
        navigator.platform ||
        navigator.userAgent ||
        '';
    return /mac|iphone|ipad|ipod/i.test(platform);
}

let initialized = false;

export function initScrollbars(): void {
    if (initialized || typeof window === 'undefined') {
        return;
    }
    initialized = true;

    // The custom scrollbars only enhance platforms with chunky native
    // scrollbars (Windows, Linux). Apple platforms keep their native overlay
    // scrollbars untouched, so the `.not-apple` gate is never applied there.
    if (isApplePlatform()) {
        return;
    }

    document.documentElement.classList.add('not-apple');

    // Capture phase so we catch scrolls on any nested scroll container, since
    // scroll events do not bubble.
    window.addEventListener('scroll', (event) => flag(event.target), {
        capture: true,
        passive: true,
    });
}
