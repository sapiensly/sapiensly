import { describe, expect, it } from 'vitest';
import { runtimeWord } from './words';

/**
 * The runtime's own words, in the app's language.
 *
 * A generated Spanish app told its users "No related records." and "No data to
 * plot." — English hardcoded in five components, seen by every app that was
 * not written in English. The four components that DID translate each carried
 * their own private map, so which language you got depended on which one
 * happened to be speaking.
 */
describe('a phrase the runtime says on its own behalf', () => {
    it('speaks the language the app was written in', () => {
        expect(runtimeWord('es-MX', 'no_related')).toContain('ligado');
        expect(runtimeWord('fr-FR', 'no_related')).toContain('lié');
        expect(runtimeWord('pt-BR', 'no_data')).toContain('plotar');
    });

    it('falls back to a word, never to a key', () => {
        // A missing translation should read as English, not as `no_data`.
        expect(runtimeWord('de-DE', 'no_data')).toBe('Nothing to plot.');
        expect(runtimeWord(undefined, 'menu')).toBe('Menu');
    });

    it('takes the language from the locale, not the region', () => {
        expect(runtimeWord('es-AR', 'menu')).toBe(runtimeWord('es-ES', 'menu'));
    });

    it('fills what the sentence leaves open', () => {
        expect(runtimeWord('es', 'step_of', { n: 3, total: 7 })).toBe(
            'Paso 3 de 7',
        );
        expect(runtimeWord('en', 'showing_of', { n: 10, total: 60 })).toBe(
            'Showing 10 of 60',
        );
    });

    it('says every phrase in every language it claims to speak', () => {
        // A half-translated dictionary is how one card ends up English inside
        // an otherwise Spanish page.
        const keys = Object.keys(JSON.parse(JSON.stringify({ ...enKeys() })));

        for (const lang of ['es', 'pt', 'fr']) {
            for (const key of keys) {
                expect(
                    runtimeWord(lang, key),
                    `${lang} is missing "${key}"`,
                ).not.toBe(runtimeWord('en', key));
            }
        }
    });
});

/** The English set, as the list every other language has to match. */
function enKeys(): Record<string, string> {
    return {
        columns: '',
        search: '',
        no_matches: '',
        of_loaded: '',
        showing_of: '',
        no_records: '',
        no_related: '',
        no_data: '',
        step_of: '',
        load_failed: '',
        retry: '',
        picker_search: '',
        picker_none: '',
        picker_more: '',
        picker_clear: '',
        picker_unavailable: '',
        filter_all: '',
        range_today: '',
        range_7d: '',
        range_30d: '',
        range_90d: '',
        range_1y: '',
        range_all: '',
        range_all_label: '',
    };
}
