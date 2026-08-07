import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FieldValue from './FieldValue.vue';
import type { DisplayContext } from './fieldDisplay';

/**
 * Three things a value deserves better than its text.
 *
 * A read-only field printed whatever the formatter returned, which for these
 * three is a DESCRIPTION of the value rather than the value: coordinates you
 * cannot go to, «1786121027607827959633554434497113.jpg» for the photo that IS
 * the evidence, and «<p>Nada.</p>» for a note whose content is the word Nada.
 *
 * Decided in this one component on purpose — the table, the record detail, the
 * related list and the kanban card all render through it, so none of them can
 * be the one that still shows a filename.
 */
const context: DisplayContext = { locale: 'es-MX', defaultCurrency: 'MXN' };

const value = (field: Record<string, unknown>, v: unknown, size = 'md') =>
    mount(FieldValue, {
        props: {
            field: { id: 'fld_1', slug: 'f', name: 'F', ...field } as never,
            value: v,
            context,
            size: size as 'sm' | 'md',
        },
    });

const photo = {
    file_id: 'fil_1',
    url: '/r/app/files/fil_1',
    original_name: '1786121027607827959633554434497113.jpg',
    mime: 'image/jpeg',
};

describe('a point on the earth', () => {
    it('offers a way to go and see it', () => {
        const w = value({ type: 'geo' }, { lat: 25.660308, lng: -100.292915 });

        const link = w.find('a[target="_blank"]');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toContain('25.660308,-100.292915');
        expect(w.text()).toContain('Ver en el mapa');
    });

    it('keeps the coordinates beside it', () => {
        // They are what gets copied into a report, read out over a radio, or
        // checked against another system.
        const w = value({ type: 'geo' }, { lat: 25.660308, lng: -100.292915 });

        expect(w.text()).toContain('25.660308, -100.292915');
    });

    it('offers nothing when there is no point', () => {
        expect(value({ type: 'geo' }, null).find('a').exists()).toBe(false);
    });
});

describe('a file that is a picture', () => {
    it('is shown rather than named', () => {
        const w = value({ type: 'file' }, photo);

        expect(w.find('img').attributes('src')).toBe('/r/app/files/fil_1');
        expect(w.text()).not.toContain('1786121027607827959633554434497113');
    });

    it('opens at full size when the thumbnail is clicked', async () => {
        // A thumbnail is enough to know WHICH photo it is and never enough to
        // read a meter or check a signature — which is why the field exists.
        const w = value({ type: 'file' }, photo);

        expect(document.querySelector('[data-sp-lightbox]')).toBeNull();
        await w.find('button').trigger('click');
        expect(document.querySelector('[data-sp-lightbox]')).not.toBeNull();

        w.unmount();
    });

    it('gives a signature something to be seen against', async () => {
        // A signature is near-black strokes on a TRANSPARENT canvas — the pad
        // clears rather than fills — so on a dark surface it renders as
        // nothing. Both the thumbnail and the viewer paint a ground under it;
        // for an opaque photo that ground is never seen.
        const w = value(
            { type: 'file' },
            { ...photo, original_name: 'firma.png', mime: 'image/png' },
        );

        expect(w.find('img').classes()).toContain('bg-white');

        await w.find('button').trigger('click');
        const full = document.querySelector('[data-sp-lightbox] img');
        expect(full!.className).toContain('bg-white');

        w.unmount();
    });

    it('says when the bytes are still only on this device', () => {
        // A thumbnail identical to an uploaded one has somebody believe the
        // photo is in the record.
        const w = value({ type: 'file' }, { ...photo, pending: true });

        expect(w.text()).toContain('En este dispositivo');
    });

    it('still names anything that is not a picture, but as a link', () => {
        const w = value(
            { type: 'file' },
            {
                ...photo,
                original_name: 'contrato.pdf',
                mime: 'application/pdf',
            },
        );

        expect(w.find('img').exists()).toBe(false);
        expect(w.text()).toContain('contrato.pdf');
        expect(w.find('a').attributes('href')).toBe('/r/app/files/fil_1');
    });
});

describe('a field whose value is markup', () => {
    it('renders rich text instead of printing its tags', () => {
        const w = value({ type: 'rich_text' }, '<p>Nada.</p>');

        expect(w.find('.sp-rich').html()).toContain('<p>Nada.</p>');
        expect(w.text()).toBe('Nada.');
    });

    it('renders the markdown people type into a long text box', () => {
        const w = value({ type: 'long_text' }, '- uno\n- dos');

        expect(w.find('.sp-rich').html()).toContain('<li>');
    });

    it('strips whatever the markup was in a table cell', () => {
        // One line and no room to render anything — but printing the tags raw
        // is worse than either.
        const w = value({ type: 'rich_text' }, '<p>Nada.</p>', 'sm');

        expect(w.find('.sp-rich').exists()).toBe(false);
        expect(w.text()).toBe('Nada.');
    });

    it('does not let a record carry a script into the page', () => {
        // The content is the tenant's own, but it reaches this browser through
        // a record anybody with write access could have filled in — including,
        // on a public portal, a stranger.
        const w = value(
            { type: 'rich_text' },
            '<p>Hola</p><script>alert(1)<\/script><img src=x onerror=alert(1)>',
        );

        const html = w.find('.sp-rich').html();
        expect(html).not.toContain('<script');
        expect(html).not.toContain('onerror');
        expect(html).toContain('Hola');
    });
});
