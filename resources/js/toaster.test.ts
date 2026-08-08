import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * The toast that says a record was saved.
 *
 * vue-sonner ships its stylesheet SEPARATELY from v2 and nothing imported it,
 * so every toast in the app rendered as bare DOM at the end of the body — a
 * full-bleed line across the bottom of the phone with the icon stacked on top
 * of the text. Everything that makes a toast a toast is in that file:
 * `position: fixed`, the width, the card, the shadow, the corner it flies into.
 *
 * It survived because it still SAID the right thing. Nothing throws, nothing
 * logs, no test fails and the browser suite is happy — a toast with no CSS is a
 * div with the correct words in it. The only way to find it is to look at a
 * screenshot, which is exactly the kind of thing a file read can answer for
 * free instead.
 */
const source = (name: string): string =>
    readFileSync(resolve(process.cwd(), `resources/js/${name}`), 'utf8');

describe('the app entry', () => {
    it('imports the stylesheet of the toaster it mounts', () => {
        const app = source('app.ts');

        expect(app).toContain('Toaster');
        expect(app).toContain("import 'vue-sonner/style.css'");
    });

    it('mounts the wrapper that binds a toast to the app theme', () => {
        // sonner's own theming is a prop that defaults to light, so the bare
        // Toaster puts a white card over a dark app. The project's wrapper maps
        // the surface to the same --popover tokens every other panel uses, so a
        // toast follows the appearance the person chose.
        const app = source('app.ts');

        expect(app).toContain("from './components/ui/sonner'");
        expect(app).not.toContain("Toaster } from 'vue-sonner'");
    });
});

describe('the toaster wrapper', () => {
    it('renders, which nothing had ever asked it to do', async () => {
        // It sat in components/ui unused from the day it was generated: the
        // entry mounted the bare Toaster instead. Wiring it up without once
        // mounting it would be trading a visible bug for a blank screen.
        const { mount } = await import('@vue/test-utils');
        const { Toaster } = await import('./components/ui/sonner');

        const wrapper = mount(Toaster, {
            props: { richColors: true, position: 'top-right' },
        });

        expect(wrapper.html()).toContain('data-sonner-toaster');
    });
});
