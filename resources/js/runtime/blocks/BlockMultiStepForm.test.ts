import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import BlockMultiStepForm from './BlockMultiStepForm.vue';

/**
 * The wizard says how far along it is, in words.
 *
 * The numbered bubbles carry the step titles, and translated titles wrap or
 * spill on a narrow screen — at which point the bubbles alone stop answering
 * the only question the reader actually has, which is how much of this is
 * left. The count in words survives any label.
 */
const step = (n: number) => ({
    id: `stp_${n}`,
    title: `Paso número ${n} con un título largo`,
    fields: [{ field_id: `fld_${n}` }],
});

function wizard(props: Record<string, unknown> = {}) {
    return mount(BlockMultiStepForm, {
        props: {
            block: {
                id: 'blk_w1',
                type: 'multi_step_form',
                object_id: 'obj_1',
                mode: 'create',
                steps: [step(1), step(2), step(3)],
            },
            objects: [
                {
                    id: 'obj_1',
                    slug: 'items',
                    name: 'Items',
                    fields: [1, 2, 3].map((n) => ({
                        id: `fld_${n}`,
                        slug: `f${n}`,
                        name: `Campo ${n}`,
                        type: 'string',
                    })),
                },
            ],
            locale: 'es-MX',
            defaultCurrency: 'MXN',
            ...props,
        },
        global: { stubs: { FormFieldInput: true, RuntimeIcon: true } },
    });
}

describe('a multi-step form', () => {
    it('says which step of how many, in the app language', () => {
        expect(wizard().text()).toContain('Paso 1 de 3');
    });

    it('translates the counter with the app, not the session', () => {
        expect(wizard({ locale: 'fr-FR' }).text()).toContain('Étape 1 sur 3');
        expect(wizard({ locale: 'en-US' }).text()).toContain('Step 1 of 3');
    });

    it('falls back to English for a language it has no words for', () => {
        expect(wizard({ locale: 'de-DE' }).text()).toContain('Step 1 of 3');
    });

    it('keeps the counter out of the way when progress is switched off', () => {
        const off = wizard({
            block: {
                id: 'blk_w1',
                type: 'multi_step_form',
                object_id: 'obj_1',
                mode: 'create',
                show_progress: false,
                steps: [step(1), step(2)],
            },
        });

        expect(off.text()).not.toContain('Paso 1');
    });
});
