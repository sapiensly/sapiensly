import { useMediaQuery } from '@vueuse/core';

/**
 * The single definition of the app-shell breakpoint.
 *
 * At `lg` (1024px) and above the sidebar is a persistent column; below it,
 * navigation moves into an off-canvas drawer. Anything that needs to *behave*
 * differently across that boundary (not just look different) reads this —
 * pure styling should keep using the `lg:` Tailwind prefix instead.
 *
 * Server-side this resolves to `false`; that only affects click handlers,
 * which run after hydration when the query is live.
 */
export function useIsDesktop() {
    return useMediaQuery('(min-width: 1024px)');
}
