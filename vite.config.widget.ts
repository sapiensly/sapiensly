import { defineConfig } from 'vite';

/**
 * Vite configuration for building the embeddable widget.
 *
 * This builds a standalone IIFE JavaScript file that can be
 * embedded on any website via a script tag.
 *
 * Usage: npm run build:widget
 *
 * Output: ~23KB minified, ~6KB gzipped
 */
export default defineConfig({
    publicDir: false, // Disable public dir copying to avoid circular issues
    build: {
        lib: {
            entry: 'resources/widget/src/index.ts',
            name: 'SapienslyWidget',
            fileName: () => 'widget.js',
            formats: ['iife'],
        },
        outDir: 'public/widget/v1',
        emptyOutDir: false,
        copyPublicDir: false,
        // Target modern browsers for smaller bundle
        target: 'es2020',
        minify: 'terser',
        terserOptions: {
            compress: {
                drop_console: true,
                drop_debugger: true,
                pure_funcs: ['console.log', 'console.info', 'console.debug'],
                passes: 2,
            },
            mangle: {
                safari10: true,
            },
            format: {
                comments: false,
            },
        },
        rollupOptions: {
            output: {
                // Ensure CSS is inlined
                inlineDynamicImports: true,
            },
        },
    },
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
});
