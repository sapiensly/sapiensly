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
        activity: 'Activity',
        leave_a_note: 'Leave a note…',
        comment_send: 'Comment',
        no_activity: 'Nothing has happened here yet.',
        by_the_app: 'The app',
        event_created: 'created it',
        event_updated: 'changed it',
        event_deleted: 'deleted it',
        event_comment: 'wrote',
        bool_yes: 'Yes',
        bool_no: 'No',
        demo_banner: 'Demo environment',
        demo_explains: 'Nothing here is real data.',
        demo_leave: 'Back to production',
        demo_enter: 'Open the demo',
        demo_reset: 'Empty it',
        demo_reset_sure: 'Delete every demo record?',
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
        activity: 'Actividad',
        leave_a_note: 'Deja una nota…',
        comment_send: 'Comentar',
        no_activity: 'Todavía no ha pasado nada aquí.',
        by_the_app: 'La app',
        event_created: 'lo creó',
        event_updated: 'lo cambió',
        event_deleted: 'lo eliminó',
        event_comment: 'escribió',
        bool_yes: 'Sí',
        bool_no: 'No',
        demo_banner: 'Entorno de demo',
        demo_explains: 'Nada de esto son datos reales.',
        demo_leave: 'Volver a producción',
        demo_enter: 'Abrir la demo',
        demo_reset: 'Vaciarla',
        demo_reset_sure: '¿Borrar todos los registros de demo?',
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
        activity: 'Atividade',
        leave_a_note: 'Deixe uma nota…',
        comment_send: 'Comentar',
        no_activity: 'Ainda não aconteceu nada aqui.',
        by_the_app: 'O app',
        event_created: 'criou',
        event_updated: 'alterou',
        event_deleted: 'excluiu',
        event_comment: 'escreveu',
        bool_yes: 'Sim',
        bool_no: 'Não',
        demo_banner: 'Ambiente de demonstração',
        demo_explains: 'Nada aqui são dados reais.',
        demo_leave: 'Voltar à produção',
        demo_enter: 'Abrir a demonstração',
        demo_reset: 'Esvaziar',
        demo_reset_sure: 'Excluir todos os registros de demonstração?',
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
        activity: 'Activité',
        leave_a_note: 'Laissez une note…',
        comment_send: 'Commenter',
        no_activity: 'Il ne s’est encore rien passé ici.',
        by_the_app: 'L’application',
        event_created: 'l’a créé',
        event_updated: 'l’a modifié',
        event_deleted: 'l’a supprimé',
        event_comment: 'a écrit',
        bool_yes: 'Oui',
        bool_no: 'Non',
        demo_banner: 'Environnement de démo',
        demo_explains: 'Rien ici n’est une donnée réelle.',
        demo_leave: 'Retour à la production',
        demo_enter: 'Ouvrir la démo',
        demo_reset: 'La vider',
        demo_reset_sure: 'Supprimer tous les enregistrements de démo ?',
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
