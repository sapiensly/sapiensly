/**
 * The ENTIRE Lucide icon set as ONE lazy chunk. This module is only ever
 * reached through `import('./lucide-full')` inside resolveIconLazy, so Rollup
 * emits it as a single self-contained chunk that loads once (then browser +
 * module cache keep it) the first time a dashboard needs an icon outside the
 * curated eager registry.
 *
 * Why one chunk and NOT `import.meta.glob(...*.mjs)`: the glob compiles to
 * ~1,700 individual dynamic imports whose preload-dependency tables get
 * stitched into every common app chunk. In production that exploded into
 * hundreds of tiny asset requests (and, on any imperfect deploy, hundreds of
 * 404s that broke page hydration). A single namespace import bundles the whole
 * set into one deploy-atomic file instead.
 *
 * `import *` deliberately defeats tree-shaking here — bundling the full set is
 * the point; the cost is paid once, lazily, only when an uncurated icon
 * actually appears.
 */
import * as Lucide from '@lucide/vue';
import type { Component } from 'vue';

/** kebab-case ("chart-column") → PascalCase export ("ChartColumn"). */
function kebabToPascal(name: string): string {
    return name
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join('');
}

const exports = Lucide as unknown as Record<string, Component>;

/** Resolve a normalized kebab-case Lucide name against the full set, or null. */
export function lookupLucide(kebabName: string): Component | null {
    return exports[kebabToPascal(kebabName)] ?? null;
}
