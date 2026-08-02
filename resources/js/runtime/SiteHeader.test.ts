import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SiteHeader from './SiteHeader.vue';

/**
 * The bar, on its own.
 *
 * This file exists because rewriting this component left the whole page blank
 * and took three attempts to diagnose. The cause was a `ref` written from a
 * `:ref` callback during render — reactive state mutated mid-render schedules
 * another render, and Vue abandons the tree past its recursive-update ceiling,
 * leaving a warning rather than an error. A browser test PASSED on that blank
 * page: `assertNoJavaScriptErrors` sees nothing, because Vue swallowed it.
 *
 * Mounting the component throws or renders. There is no third outcome to
 * mistake for success.
 */
const pages = (n: number) =>
    [
        'Clientes',
        'Oportunidades',
        'Actividades',
        'Contratos',
        'Facturas',
        'Incidencias',
    ]
        .slice(0, n)
        .map((name) => ({ slug: name.toLowerCase(), name }));

function header(props: Record<string, unknown> = {}) {
    return mount(SiteHeader, {
        props: {
            brand: { name: 'Mi App' },
            pages: pages(4),
            currentSlug: 'clientes',
            hrefFor: (slug: string) => `/r/app/${slug}`,
            locale: 'es-MX',
            ...props,
        },
        global: {
            stubs: {
                NotificationBell: true,
                PortalAuthBar: true,
                RuntimeUserMenu: true,
            },
        },
    });
}

describe('the app bar', () => {
    it('renders at all', () => {
        // The assertion the browser could not make. A component that dies
        // during render produced an empty page and a green test.
        const bar = header();

        expect(bar.html()).toContain('Mi App');
        expect(bar.findAll('nav a')).toHaveLength(4);
    });

    it('marks where the reader is', () => {
        const bar = header({ currentSlug: 'contratos' });
        const active = bar
            .findAll('nav a')
            .find((a) => a.text() === 'Contratos');

        // The active pill is an inline style rather than a class, so this is
        // the only way to see it without a browser.
        expect(active?.attributes('style')).toContain('accent');
    });

    it('keeps every destination reachable from the folded menu too', () => {
        // Today both are rendered and a breakpoint chooses: the row above `sm`,
        // the menu below. jsdom applies no CSS, so this sees both — which is
        // the point worth pinning. Whichever one the reader gets, the same
        // destinations are in it, so narrowing a window can never lose one.
        const bar = header();
        bar.find('[data-sp-nav-menu]').trigger('click');

        return bar.vm.$nextTick().then(() => {
            const inMenu = bar.findAll('[role="menu"] a').map((a) => a.text());

            expect(inMenu).toEqual([
                'Clientes',
                'Oportunidades',
                'Actividades',
                'Contratos',
            ]);
        });
    });

    it('shows nothing to navigate when the app has one page', () => {
        // A single-object app has no navigation to speak of; a bar with one
        // link in it is noise.
        expect(
            header({ pages: pages(1) })
                .find('nav')
                .exists(),
        ).toBe(false);
    });

    it('speaks the app language, not the platform session', () => {
        // A Spanish app inside an English session was labelling its own
        // navigation in English.
        const bar = header({ pages: pages(6), locale: 'fr-FR' });

        expect(bar.html()).not.toContain('Menú');
    });
});
