import '../css/app.css';
/**
 * vue-sonner ships its stylesheet SEPARATELY from v2, and nothing imported it.
 * Everything that makes a toast a toast lives in there — `position: fixed`, the
 * width, the card, the shadow, the corner it flies into — so every toast in the
 * app rendered as bare DOM at the end of the body: a full-bleed line at the
 * bottom of the screen with the icon on top of the text. It still SAID the
 * right thing, which is why it survived: nothing throws, nothing logs, and the
 * only way to see it is to look.
 */
import 'vue-sonner/style.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
/**
 * The project's own wrapper rather than the bare Toaster: it binds the toast
 * surface to the app's `--popover` tokens, so a toast follows the appearance
 * the person CHOSE. sonner's own theming is a prop defaulting to light, which
 * would put a white card over a dark app.
 */
import { Toaster } from './components/ui/sonner';
import { initializeTheme } from './composables/useAppearance';
import { createI18nInstance } from './i18n';
import { initScrollbars } from './lib/scrollbars';
import { registerServiceWorker } from './lib/serviceWorker';

const appName = import.meta.env.VITE_APP_NAME || 'Sapiensly';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const locale = (props.initialPage.props.locale as string) || 'en';
        const i18n = createI18nInstance(locale);

        createApp({
            render: () =>
                h('div', [
                    h(App, props),
                    h(Toaster, { richColors: true, position: 'top-right' }),
                ]),
        })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// Reveal scrollbars only while scrolling (fade out when idle)...
initScrollbars();

// Let a built app open without a signal. Registers after `load`, only in a
// production build, and only ever serves /r and /a — see public/sw.js.
registerServiceWorker();
