import { inject, type Ref, watch } from 'vue';

/**
 * Re-run `refresh` whenever the app's colour palette changes.
 *
 * Chart components that resolve CSS custom properties (`var(--sp-chart-N)`) to
 * a CONCRETE hex — for canvas drawing or computed gradients — can't rely on the
 * browser to re-resolve when the variable's value changes: the prop string
 * stays `var(--sp-chart-N)` and the component is reused (not remounted) across a
 * palette switch, so it keeps the stale colour. The runtime surface and the
 * builder preview `provide` a reactive `paletteSignal`; those components call
 * this to re-read their colours when it changes. A no-op on surfaces that don't
 * provide it (admin, slides), so the components stay drop-in anywhere.
 */
export function onPaletteChange(refresh: () => void): void {
    const signal = inject<Ref<unknown> | null>('paletteSignal', null);
    if (signal) {
        // flush:'post' is load-bearing, not a nicety. `refresh` re-reads the
        // palette by calling getComputedStyle for `--sp-chart-*` off the surface.
        // The signal and the surface's palette style share ONE reactive source,
        // so they update in the same flush — and a default 'pre' watcher runs
        // BEFORE Vue patches that style onto the DOM, so it reads the OLD vars and
        // the chart keeps its previous colour until some later re-render happens
        // to re-sync it (the "some charts take much longer" lag). 'post' runs the
        // re-read after the DOM is patched, so the new palette lands at once.
        watch(signal, () => refresh(), { deep: true, flush: 'post' });
    }
}
