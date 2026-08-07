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
        // Today a breakpoint chooses: the row above `sm`, the menu below.
        // jsdom applies no CSS so it sees both — which is the point worth
        // pinning. Whichever the reader gets, the same destinations are in
        // it, so narrowing a window can never lose one.
        const bar = header();
        bar.find('[data-sp-nav-menu]').trigger('click');

        return bar.vm.$nextTick().then(() => {
            expect(bar.findAll('[role="menu"] a').map((a) => a.text())).toEqual(
                ['Clientes', 'Oportunidades', 'Actividades', 'Contratos'],
            );
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

describe('the brand block on a narrow screen', () => {
    /** The element carrying the app's name, or null when it is not rendered. */
    const name = (bar: ReturnType<typeof header>) =>
        bar.findAll('span').find((s) => s.text() === 'Mi App') ?? null;

    it('drops the name on a phone when a logo already says it', () => {
        // «Servicio Campo v11» beside a logo wrapped onto three lines and ran
        // under the menu. The name is the first thing to go, because it is the
        // only thing in that row already said by something else.
        const bar = header({ brand: { name: 'Mi App', logo: '/logo.svg' } });

        expect(name(bar)!.classes()).toContain('hidden');
        expect(name(bar)!.classes()).toContain('sm:block');
    });

    it('keeps it when there is no logo to carry it', () => {
        // Then it is the ONLY thing identifying the app, and a header with no
        // identity is worse than a long one.
        const bar = header({ brand: { name: 'Mi App' } });

        expect(name(bar)!.classes()).not.toContain('hidden');
    });

    it('truncates it either way, so a long name cannot do this again', () => {
        for (const brand of [
            { name: 'Mi App' },
            { name: 'Mi App', logo: '/logo.svg' },
        ]) {
            expect(name(header({ brand }))!.classes()).toContain('truncate');
        }
    });
});
