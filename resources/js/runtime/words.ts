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
        picker_search: 'Search a record…',
        picker_none: 'No record matches',
        picker_more: 'Keep typing to narrow it down',
        picker_clear: 'Clear',
        picker_unavailable: 'Not available here',
        filter_all: 'All',
        range_today: 'Today',
        range_7d: '7 d',
        range_30d: '30 d',
        range_90d: '90 d',
        range_1y: 'Year',
        range_all: 'All time',
        range_all_label: 'the whole history',
        cancel: 'Cancel',
        confirm: 'Confirm',
        delete: 'Delete',
        thin_series: 'Not enough history yet to show a trend.',
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
        picker_search: 'Busca un registro…',
        picker_none: 'Ningún registro coincide',
        picker_more: 'Sigue escribiendo para acotar',
        picker_clear: 'Quitar',
        picker_unavailable: 'No disponible aquí',
        filter_all: 'Todos',
        range_today: 'Hoy',
        range_7d: '7 d',
        range_30d: '30 d',
        range_90d: '90 d',
        range_1y: 'Año',
        range_all: 'Todo',
        range_all_label: 'todo el histórico',
        cancel: 'Cancelar',
        confirm: 'Confirmar',
        delete: 'Eliminar',
        thin_series:
            'Todavía no hay suficiente historia para ver una tendencia.',
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
        picker_search: 'Busque um registro…',
        picker_none: 'Nenhum registro corresponde',
        picker_more: 'Continue digitando para restringir',
        picker_clear: 'Remover',
        picker_unavailable: 'Não disponível aqui',
        filter_all: 'Todos',
        range_today: 'Hoje',
        range_7d: '7 d',
        range_30d: '30 d',
        range_90d: '90 d',
        range_1y: 'Ano',
        range_all: 'Tudo',
        range_all_label: 'todo o histórico',
        cancel: 'Cancelar',
        confirm: 'Confirmar',
        delete: 'Excluir',
        thin_series:
            'Ainda não há histórico suficiente para ver uma tendência.',
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
        picker_search: 'Cherchez un enregistrement…',
        picker_none: 'Aucun enregistrement ne correspond',
        picker_more: 'Continuez à taper pour affiner',
        picker_clear: 'Retirer',
        picker_unavailable: 'Indisponible ici',
        filter_all: 'Tous',
        range_today: 'Aujourd’hui',
        range_7d: '7 j',
        range_30d: '30 j',
        range_90d: '90 j',
        range_1y: 'Année',
        range_all: 'Tout',
        range_all_label: 'tout l’historique',
        cancel: 'Annuler',
        confirm: 'Confirmer',
        delete: 'Supprimer',
        thin_series: 'Pas encore assez d’historique pour voir une tendance.',
    },
};

/**
 * The keys a language is missing against the English table.
 *
 * Asked this way rather than by comparing VALUES: an abbreviation is often the
 * same string in several languages ("30 d"), and a value-difference check
 * reports those as untranslated while quietly accepting a key that fell back.
 * Mirrors DocWords::missingKeys on the PHP side.
 */
export function missingWordKeys(lang: string): string[] {
    const table = WORDS[lang] ?? {};

    return Object.keys(WORDS.en).filter((key) => !(key in table));
}

/** Every language the runtime claims to speak. */
export function wordLanguages(): string[] {
    return Object.keys(WORDS);
}

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
