import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BlockForm from './BlockForm.vue';

/**
 * A form on a phone is mostly the space between its fields.
 *
 * Every field used to reserve one line under itself for help or an error, so
 * that a failed validation would not shove the rest of the form down. On a
 * desktop that line is invisible; on a phone it is the whole problem —
 * seventeen pixels of nothing under each of ten fields is two fewer fields on
 * screen, and a form where you scroll past emptiness to reach the next
 * question.
 *
 * So the line is reserved only for a field that HAS help text, where the space
 * is doing work and an error can replace the help without moving anything. A
 * field with nothing to say gets the line only when it has an error.
 */
const field = (n: number, help?: string) => ({
    id: `fld_${n}`,
    slug: `f${n}`,
    name: `Campo ${n}`,
    type: 'string' as const,
    ...(help === undefined ? {} : { help_text: help }),
});

function form(fields: ReturnType<typeof field>[]) {
    return mount(BlockForm, {
        props: {
            block: {
                id: 'blk_f1',
                type: 'form' as const,
                object_id: 'obj_1',
                mode: 'create' as const,
            },
            objects: [{ id: 'obj_1', slug: 'items', name: 'Items', fields }],
            locale: 'es-MX',
            defaultCurrency: 'MXN',
        },
    });
}

/** The one line under a field: `text-[11px] leading-tight`. */
const notes = (wrapper: ReturnType<typeof form>) =>
    wrapper.findAll('p.text-\\[11px\\].leading-tight');

describe('the line under a field', () => {
    it('is not there at all when the field has nothing to say', () => {
        const wrapper = form([field(1), field(2), field(3)]);

        expect(notes(wrapper)).toHaveLength(0);
    });

    it('is there for a field whose manifest gave it help text', () => {
        const wrapper = form([field(1), field(2, 'El folio del proveedor.')]);

        const found = notes(wrapper);
        expect(found).toHaveLength(1);
        expect(found[0].text()).toBe('El folio del proveedor.');
    });

    it('only reserves space for the fields that use it', () => {
        // Nine plain fields and one with help: nine lines of nothing is what
        // this is about.
        const fields = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((n) => field(n));
        fields.push(field(10, 'Con ayuda.'));

        expect(notes(form(fields))).toHaveLength(1);
    });
});
