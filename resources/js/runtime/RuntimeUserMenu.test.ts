import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The menu that holds the things you do AS YOURSELF.
 *
 * Two of them arrived here from a row of their own above the header — «Ver la
 * app como…» and «Abrir la demo» — which spent a line of every screen, on a
 * phone about a tenth of it, telling an administrator that nothing was wrong.
 * Whether a pretence is ON is worth a bar (`PreviewBar`); the way IN to one is
 * an ordinary thing you do as yourself, and this is where those already lived.
 *
 * The dropdown primitives are stubbed to render their contents inline: reka's
 * menu mounts its content only while open and its submenu only while hovered,
 * which is a browser's question, not this component's.
 */
const state = vi.hoisted(() => ({
    user: { name: 'Ada Lovelace', email: 'ada@x.test' } as {
        name: string;
        email: string;
    } | null,
}));

const nav = vi.hoisted(() => ({ switchTo: vi.fn() }));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        usePage: () => ({
            props: { auth: state.user ? { user: state.user } : null },
        }),
        Link: defineComponent({
            setup:
                (_, { slots, attrs }) =>
                () =>
                    h('a', attrs, slots.default?.()),
        }),
    };
});

vi.mock('./previewSwitch', () => ({ switchTo: nav.switchTo }));

vi.mock('@/components/ui/dropdown-menu', async () => {
    const { defineComponent, h } = await import('vue');

    const box = defineComponent({
        setup:
            (_, { slots, attrs }) =>
            () =>
                h('div', attrs, slots.default?.()),
    });

    const item = defineComponent({
        inheritAttrs: false,
        setup:
            (_, { slots, attrs }) =>
            () =>
                h(
                    'button',
                    {
                        ...attrs,
                        onClick: () =>
                            (attrs.onSelect as (() => void) | undefined)?.(),
                    },
                    slots.default?.(),
                ),
    });

    return {
        DropdownMenu: box,
        DropdownMenuContent: box,
        DropdownMenuItem: item,
        DropdownMenuLabel: box,
        DropdownMenuSeparator: box,
        DropdownMenuSub: box,
        DropdownMenuSubContent: box,
        DropdownMenuSubTrigger: box,
        DropdownMenuTrigger: box,
    };
});

const RuntimeUserMenu = (await import('./RuntimeUserMenu.vue')).default;

const roles = [
    { slug: 'tecnico', name: 'Técnico' },
    { slug: 'admin', name: 'Admin' },
];

function menu(preview: Record<string, unknown> | null = {}, locale = 'es-MX') {
    return mount(RuntimeUserMenu, {
        global: {
            provide: {
                runtimeLocale: locale,
                previewOptions:
                    preview === null
                        ? null
                        : {
                              environment: 'production',
                              canSwitchEnvironment: true,
                              role: null,
                              roles,
                              ...preview,
                          },
            },
        },
    });
}

beforeEach(() => state && (state.user = { name: 'Ada', email: 'ada@x.test' }));

describe('the ways into a pretence', () => {
    it('offers both to somebody who may take them', () => {
        const w = menu();

        expect(w.find('[data-sp-role-open]').text()).toContain(
            'Ver la app como…',
        );
        expect(w.find('[data-sp-environment-switch="demo"]').text()).toContain(
            'Abrir la demo',
        );
    });

    it('lists every role, plus the way back to no role at all', () => {
        const w = menu();

        expect(
            w.findAll('[data-sp-role-set]').map((b) => b.text().trim()),
        ).toEqual(['Sin restricciones (tú)', 'Técnico', 'Admin']);
    });

    it('marks the one being previewed', () => {
        const w = menu({ role: 'tecnico' });
        const check = (slug: string) =>
            w.find(`[data-sp-role-set="${slug}"] svg`).classes();

        expect(check('tecnico')).not.toContain('opacity-0');
        expect(check('admin')).toContain('opacity-0');
        expect(w.find('[data-sp-role-set=""] svg').classes()).toContain(
            'opacity-0',
        );
    });

    it('drops the demo once the reader is in it', () => {
        // Getting OUT of the demo is the warning bar's job, at full weight,
        // where it cannot be missed. Repeating it here would be the third
        // place the same page says «demo».
        const w = menu({ environment: 'demo' });

        expect(w.find('[data-sp-environment-switch="demo"]').exists()).toBe(
            false,
        );
        expect(w.find('[data-sp-role-open]').exists()).toBe(true);
    });

    it('drops the roles when the app defines none', () => {
        const w = menu({ roles: [] });

        expect(w.find('[data-sp-role-open]').exists()).toBe(false);
        expect(w.find('[data-sp-environment-switch="demo"]').exists()).toBe(
            true,
        );
    });

    it('offers neither where the page never said anything about pretending', () => {
        // The same widget renders on a portal and on a public app, where there
        // is no previewOptions to inject and the menu is just the way out.
        const w = menu(null);

        expect(w.find('[data-sp-role-open]').exists()).toBe(false);
        expect(w.find('[data-sp-environment-switch="demo"]').exists()).toBe(
            false,
        );
    });

    it('reloads the page with the pretence on the url', () => {
        // Both are server-side decisions, so the server has to make them again.
        const w = menu();

        w.find('[data-sp-role-set="admin"]').trigger('click');
        expect(nav.switchTo).toHaveBeenCalledWith('as_role', 'admin');

        w.find('[data-sp-role-set=""]').trigger('click');
        expect(nav.switchTo).toHaveBeenCalledWith('as_role', null);

        w.find('[data-sp-environment-switch="demo"]').trigger('click');
        expect(nav.switchTo).toHaveBeenCalledWith('env', 'demo');
    });
});

describe('the menu itself', () => {
    it('speaks the app language rather than the one it was written in', () => {
        // The sign-out was hardcoded «Salir a Sapiensly», so an English app
        // said it in Spanish to its English users.
        const w = menu({}, 'en-GB');

        expect(w.text()).toContain('Exit to Sapiensly');
        expect(w.text()).toContain('View as…');
        expect(w.text()).not.toContain('Salir a Sapiensly');
    });

    it('takes the language from the app, not from the preview options', () => {
        // The sign-out is here on a public app and on a portal too, where no
        // pretence is ever offered — so reading the language off something
        // optional would have kept the hardcoded label for exactly those.
        expect(menu(null).text()).toContain('Salir a Sapiensly');
    });

    it('renders nothing at all for a reader who is not signed in', () => {
        state.user = null;
        const w = menu();

        expect(w.find('button').exists()).toBe(false);
        expect(w.text()).toBe('');
    });
});
