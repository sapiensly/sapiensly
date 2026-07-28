import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { readdirSync } from 'node:fs';
import { defineConfig } from 'vite';

/**
 * The self-hosted landing font catalog lives in public/fonts/ and landing-fonts.css
 * references it by absolute URL. laravel-vite-plugin sets `publicDir: false` (it
 * doesn't want public/ copied into the build), so Vite has no public directory to
 * resolve those URLs against and warned once per @font-face — ten lines on every
 * build about files that are present and that Laravel serves fine.
 *
 * Declaring them external is Vite's own escape hatch for this: its asset resolver
 * skips the warning for anything `build.rollupOptions.external` claims, which says
 * "resolved at runtime, on purpose" instead of muting the message.
 *
 * The list is built from the files that ACTUALLY EXIST, so a typo'd or deleted
 * face is still not external and still warns — the one case worth reading.
 */
function publicFontUrls(): string[] {
    try {
        return readdirSync('public/fonts')
            .filter((file) => file.endsWith('.woff2'))
            .map((file) => `/fonts/${file}`);
    } catch {
        return [];
    }
}

export default defineConfig({
    build: {
        rollupOptions: {
            external: publicFontUrls(),
            /**
             * reka-ui ships a bundled @vueuse/core whose `/* #__PURE__ *\/`
             * annotations sit where Rollup can't read them. It's a dependency's
             * build output, nothing we can fix and nothing we can act on — but
             * only ever silence it for third-party code, so the same mistake in
             * our own source still surfaces.
             */
            onwarn(warning, defaultHandler) {
                if (warning.code === 'INVALID_ANNOTATION' && warning.id?.includes('node_modules')) {
                    return;
                }
                defaultHandler(warning);
            },
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
