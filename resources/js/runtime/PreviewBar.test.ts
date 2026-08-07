import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PreviewBar from './PreviewBar.vue';

/**
 * The bar that says the page is not the real thing.
 *
 * It replaced two — a demo banner and a role-preview row — which between them
 * took a quarter of a phone screen to say one sentence, in an amber
 * (`text-amber-300` on `bg-amber-500/10`) chosen for the dark theme and printed
 * on a light app at about 1.5:1. Not "hard to read": not legible.
 */
const roles = [
    { slug: 'tecnico', name: 'Técnico' },
    { slug: 'admin', name: 'Admin' },
];

function bar(props: Record<string, unknown> = {}) {
    return mount(PreviewBar, {
        props: {
            environment: 'production',
            canSwitchEnvironment: true,
            role: null,
            roles,
            locale: 'es-MX',
            appSlug: 'campo',
            ...props,
        },
    });
}

const on = (w: ReturnType<typeof bar>) => w.find('[data-sp-preview-bar="on"]');

describe('when the page is pretending', () => {
    it('warns in an amber that reads on both themes', () => {
        // The class list carries a light pair AND a dark one. The old bar had
        // only the dark half, which is why it vanished on a light app.
        const classes = on(bar({ environment: 'demo' }))
            .classes()
            .join(' ');

        expect(classes).toContain('text-amber-900');
        expect(classes).toContain('bg-amber-100');
        expect(classes).toContain('dark:text-amber-200');
        expect(classes).toContain('dark:bg-amber-500/10');
    });

    it('says both pretences at once, in one bar', () => {
        // A demo environment and a role preview are the same sentence — the
        // page in front of you is not what it would be. Stacked as two bars
        // they said it twice and took twice the height.
        const w = bar({ environment: 'demo', role: 'tecnico' });

        expect(on(w).text()).toContain('Entorno de demo');
        expect(on(w).text()).toContain('Técnico');
    });

    it('leaves only the way out at full weight', () => {
        // «Vaciarla» empties the sandbox and sat beside «Volver a producción»
        // at the same weight. One of those is destructive and the other is an
        // exit.
        const w = bar({ environment: 'demo' });

        expect(
            w.find('[data-sp-environment-switch="production"]').exists(),
        ).toBe(true);
        expect(w.find('[data-sp-environment-reset]').exists()).toBe(false);
        expect(w.find('[data-sp-environment-seed]').exists()).toBe(false);
    });

    it('keeps the rest behind a menu', async () => {
        const w = bar({ environment: 'demo' });

        await w.find('[data-sp-preview-menu]').trigger('click');

        expect(w.find('[data-sp-environment-reset]').exists()).toBe(true);
        expect(w.find('[data-sp-environment-seed]').exists()).toBe(true);
        expect(w.find('select').exists()).toBe(true);
    });

    it('still asks twice before emptying the sandbox', async () => {
        // Moving it into the menu does not make it less destructive.
        const w = bar({ environment: 'demo' });
        await w.find('[data-sp-preview-menu]').trigger('click');

        const reset = w.find('[data-sp-environment-reset]');
        expect(reset.text()).toBe('Vaciarla');
        await reset.trigger('click');
        expect(w.find('[data-sp-environment-reset]').text()).toContain(
            '¿Borrar',
        );
    });

    it('spends no height on the explanation where there is none', () => {
        // «Demo» said once is the message; said in a full sentence on every
        // screen forever it is just height. The sentence returns from sm up.
        const w = bar({ environment: 'demo' });
        const sentence = w
            .findAll('span')
            .find((s) => s.text() === 'Nada de esto son datos reales.');

        expect(sentence!.classes()).toContain('hidden');
        expect(sentence!.classes()).toContain('sm:inline');
    });
});

describe('when nothing is pretending', () => {
    it('offers the ways in and warns about nothing', () => {
        // The old role bar rendered its whole row — label, select and all —
        // even with no preview running, which is how a tenth of a phone screen
        // came to be spent saying everything was normal.
        const w = bar();

        expect(on(w).exists()).toBe(false);
        expect(w.find('[data-sp-preview-bar="off"]').exists()).toBe(true);
        expect(w.find('[data-sp-environment-switch="demo"]').exists()).toBe(
            true,
        );
    });

    it('shows nothing at all to somebody who can do neither', () => {
        const w = bar({ canSwitchEnvironment: false, roles: [] });

        expect(w.find('[data-sp-preview-bar]').exists()).toBe(false);
    });
});

describe('the words it says', () => {
    it('speaks the app language rather than the one it was written in', () => {
        // The role bar was hardcoded Spanish — «Ver la app como…» — so an
        // English app said it in Spanish to its English users.
        const w = bar({ locale: 'en-GB' });

        expect(w.text()).toContain('View as…');
        expect(w.text()).not.toContain('Ver la app como');
    });
});
