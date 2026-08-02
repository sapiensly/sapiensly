import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Unit tests for the app runtime.
 *
 * The runtime is the largest piece of this codebase with no test below the
 * browser, and that cost real time before this existed: a `SiteHeader` rewrite
 * left the page blank and took three attempts and two reverts to diagnose,
 * because the only way to look at it was a screenshot of the whole app on a
 * three-minute loop. Worse, a browser test PASSED on that blank page —
 * `assertNoJavaScriptErrors` sees nothing when Vue swallows a render error as a
 * warning. Mounting the component would have thrown on the first run.
 *
 * Scope is deliberate. This covers `resources/js/runtime`: block components and
 * the pure formatting logic under them. What only a real browser can answer —
 * themes, responsive layout, a whole page's round trip — stays in
 * tests/Browser, which is slower for good reasons.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts'],
        globals: true,
        restoreMocks: true,
    },
});
