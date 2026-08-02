/**
 * The runtime's own words.
 *
 * Not the app's content — that comes from the manifest, in whatever language it
 * was written. These are the handful of things the runtime says on its own
 * behalf: an empty table, a search box, the count of what did not fit.
 *
 * They lived as hardcoded English in five components and as four separate
 * word-maps in four others, so a Spanish app told its user "No related
 * records." and an English one had a menu labelled "Menú" depending on which
 * component was speaking. One place, keyed by the APP's locale rather than the
 * platform session's: an app is read by its users, who did not choose the
 * language of the person who built it.
 *
 * English is the fallback for a language not listed, which is a choice about
 * being readable rather than about being right — a missing translation should
 * degrade to a word, not to a key.
 */
type Dict = Record<string, string>;

const WORDS: Record<string, Dict> = {
    en: {
        columns: 'Columns',
        search: 'Search…',
        no_matches: 'No row matches',
        of_loaded: 'among the first {n} of {total}',
        showing_of: 'Showing {n} of {total}',
        no_records: 'Nothing here yet.',
        no_related: 'Nothing linked to this record yet.',
        no_data: 'Nothing to plot.',
        menu: 'Menu',
        step_of: 'Step {n} of {total}',
        load_failed: 'This section could not be loaded',
        retry: 'Try again',
    },
    es: {
        columns: 'Columnas',
        search: 'Buscar…',
        no_matches: 'Ninguna fila coincide con',
        of_loaded: 'entre los primeros {n} de {total}',
        showing_of: 'Mostrando {n} de {total}',
        no_records: 'Todavía no hay nada aquí.',
        no_related: 'Todavía no hay nada ligado a este registro.',
        no_data: 'No hay nada que graficar.',
        menu: 'Menú',
        step_of: 'Paso {n} de {total}',
        load_failed: 'No se pudo cargar esta sección',
        retry: 'Reintentar',
    },
    pt: {
        columns: 'Colunas',
        search: 'Pesquisar…',
        no_matches: 'Nenhuma linha corresponde a',
        of_loaded: 'entre os primeiros {n} de {total}',
        showing_of: 'Mostrando {n} de {total}',
        no_records: 'Ainda não há nada aqui.',
        no_related: 'Ainda não há nada ligado a este registro.',
        no_data: 'Não há nada para plotar.',
        menu: 'Menu',
        step_of: 'Passo {n} de {total}',
        load_failed: 'Não foi possível carregar esta seção',
        retry: 'Tentar de novo',
    },
    fr: {
        columns: 'Colonnes',
        search: 'Rechercher…',
        no_matches: 'Aucune ligne ne correspond à',
        of_loaded: 'parmi les {n} premiers sur {total}',
        showing_of: 'Affichage de {n} sur {total}',
        no_records: 'Il n’y a encore rien ici.',
        no_related: 'Rien n’est encore lié à cet enregistrement.',
        no_data: 'Rien à tracer.',
        menu: 'Menu',
        step_of: 'Étape {n} sur {total}',
        load_failed: 'Cette section n’a pas pu être chargée',
        retry: 'Réessayer',
    },
};

/**
 * One phrase, in the app's language, with its placeholders filled.
 *
 * @param locale the APP's locale, not the platform session's
 */
export function runtimeWord(
    locale: string | undefined,
    key: string,
    replace: Record<string, string | number> = {},
): string {
    const lang = (locale ?? 'en').slice(0, 2).toLowerCase();
    let out = WORDS[lang]?.[key] ?? WORDS.en[key] ?? key;

    for (const [token, value] of Object.entries(replace)) {
        out = out.replace(`{${token}}`, String(value));
    }

    return out;
}
