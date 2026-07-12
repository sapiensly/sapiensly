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
        watch(signal, () => refresh(), { deep: true });
    }
}
