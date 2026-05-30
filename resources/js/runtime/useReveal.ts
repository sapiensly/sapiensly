import { onBeforeUnmount, onMounted, type Ref } from 'vue';

/**
 * Subtle scroll-reveal for a container's DIRECT children (the top-level page
 * sections). Each child fades/slides in once as it enters the viewport. No-ops
 * when the user prefers reduced motion. Animation styles live in app.css
 * (.sp-reveal / .sp-reveal-in).
 */
export function useScrollReveal(container: Ref<HTMLElement | null>): void {
    let observer: IntersectionObserver | null = null;

    onMounted(() => {
        if (typeof window === 'undefined' || !('IntersectionObserver' in window)) return;
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;

        const root = container.value;
        if (!root) return;

        observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('sp-reveal-in');
                        observer?.unobserve(entry.target);
                    }
                }
            },
            { threshold: 0.08, rootMargin: '0px 0px -8% 0px' },
        );

        for (const child of Array.from(root.children)) {
            child.classList.add('sp-reveal');
            observer.observe(child);
        }
    });

    onBeforeUnmount(() => observer?.disconnect());
}
