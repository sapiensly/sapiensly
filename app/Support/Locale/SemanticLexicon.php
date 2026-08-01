<?php

namespace App\Support\Locale;

use Illuminate\Support\Str;

/**
 * The per-locale semantics the app scaffolder's DETERMINISTIC layer needs: the
 * chrome labels it writes ("New {x}", "{n} by status", the POS strings) and the
 * word lists its field/intent heuristics match on (which fields read like a
 * quantity, a price, an image; which object names read like commerce).
 *
 * This is the single place a language lives. Adding a locale is one entry per
 * table — every heuristic and label picks it up at once — instead of editing a
 * dozen regexes and `$lang === 'es'` ternaries scattered across the scaffolder.
 * Unknown locales resolve to English; a locale missing a specific word list or
 * label falls back to the English one, so a partial locale still works.
 *
 * Matching is accent-insensitive: haystacks and stored fragments are ASCII-folded
 * ("Quantité" matches the fragment "quantite"), so store fragments without
 * accents. Fragments are regex alternatives — use \b for short ambiguous words.
 */
class SemanticLexicon
{
    /** Locales with their own tables; any other input resolves to 'en'. */
    public const SUPPORTED = ['en', 'es', 'pt', 'fr'];

    /**
     * Chrome labels. `{s}` = the singular object name, `{n}` = the object name.
     * Each locale's template is independent (some interpolate, some do not).
     *
     * @var array<string, array<string, string>>
     */
    private const LABELS = [
        'en' => [
            'new' => 'New {s}', 'submit' => 'Create', 'saved' => '{s} created', 'created_col' => 'Created',
            'by_status' => '{n} by status', 'by_field' => '{n} by {f}', 'total' => '{n} total', 'average' => '{n} average', 'count_of' => 'Number of {n}',
            'over_time' => '{n} over time', 'value_by_status' => '{n} value by status',
            'open' => 'Open', 'detail' => 'Detail', 'report' => 'Report',
            'view_list' => 'List', 'view_board' => 'Board', 'view_calendar' => 'Calendar', 'view_timeline' => 'Timeline',
            'edit' => 'Edit', 'edit_title' => 'Edit {s}', 'save' => 'Save changes',
            'trend' => 'Trend', 'breakdown' => 'Breakdown', 'insights' => 'Key readings',
            'pos' => 'Point of Sale', 'new_order' => 'New order', 'order' => 'Order', 'qty' => 'Quantity',
            'unit_price' => 'Unit price', 'photo' => 'Photo', 'subtotal' => 'Subtotal', 'total_word' => 'Total',
            'cart_empty' => 'Open an order and add products.',
        ],
        'es' => [
            'new' => 'Agregar {s}', 'submit' => 'Guardar', 'saved' => 'Guardado', 'created_col' => 'Creado',
            'by_status' => '{n} por estado', 'by_field' => '{n} por {f}', 'total' => 'Total {n}', 'average' => 'Promedio {n}', 'count_of' => 'N.º de {n}',
            'over_time' => '{n} en el tiempo', 'value_by_status' => 'Valor de {n} por estado',
            'open' => 'Abrir', 'detail' => 'Detalle', 'report' => 'Reporte',
            'view_list' => 'Lista', 'view_board' => 'Tablero', 'view_calendar' => 'Calendario', 'view_timeline' => 'Cronograma',
            'edit' => 'Editar', 'edit_title' => 'Editar {s}', 'save' => 'Guardar cambios',
            'trend' => 'Tendencia', 'breakdown' => 'Desglose', 'insights' => 'Lecturas clave',
            'pos' => 'Punto de venta', 'new_order' => 'Nueva orden', 'order' => 'Pedido', 'qty' => 'Cantidad',
            'unit_price' => 'Precio unitario', 'photo' => 'Foto', 'subtotal' => 'Subtotal', 'total_word' => 'Total',
            'cart_empty' => 'Abre una orden y agrega productos.',
        ],
        'pt' => [
            'new' => 'Adicionar {s}', 'submit' => 'Salvar', 'saved' => 'Salvo', 'created_col' => 'Criado',
            'by_status' => '{n} por status', 'by_field' => '{n} por {f}', 'total' => 'Total de {n}', 'average' => 'Média de {n}', 'count_of' => 'N.º de {n}',
            'over_time' => '{n} ao longo do tempo', 'value_by_status' => 'Valor de {n} por status',
            'open' => 'Abrir', 'detail' => 'Detalhe', 'report' => 'Relatório',
            'view_list' => 'Lista', 'view_board' => 'Quadro', 'view_calendar' => 'Calendário', 'view_timeline' => 'Cronograma',
            'edit' => 'Editar', 'edit_title' => 'Editar {s}', 'save' => 'Salvar alterações',
            'trend' => 'Tendência', 'breakdown' => 'Detalhamento', 'insights' => 'Leituras-chave',
            'pos' => 'Ponto de venda', 'new_order' => 'Novo pedido', 'order' => 'Pedido', 'qty' => 'Quantidade',
            'unit_price' => 'Preço unitário', 'photo' => 'Foto', 'subtotal' => 'Subtotal', 'total_word' => 'Total',
            'cart_empty' => 'Abra um pedido e adicione produtos.',
        ],
        'fr' => [
            'new' => 'Ajouter {s}', 'submit' => 'Enregistrer', 'saved' => 'Enregistré', 'created_col' => 'Créé',
            'by_status' => '{n} par statut', 'by_field' => '{n} par {f}', 'total' => 'Total {n}', 'average' => 'Moyenne {n}', 'count_of' => 'Nombre de {n}',
            'over_time' => '{n} dans le temps', 'value_by_status' => 'Valeur de {n} par statut',
            'open' => 'Ouvrir', 'detail' => 'Détail', 'report' => 'Rapport',
            'view_list' => 'Liste', 'view_board' => 'Tableau', 'view_calendar' => 'Calendrier', 'view_timeline' => 'Chronologie',
            'edit' => 'Modifier', 'edit_title' => 'Modifier {s}', 'save' => 'Enregistrer les modifications',
            'trend' => 'Tendance', 'breakdown' => 'Répartition', 'insights' => 'Lectures clés',
            'pos' => 'Point de vente', 'new_order' => 'Nouvelle commande', 'order' => 'Commande', 'qty' => 'Quantité',
            'unit_price' => 'Prix unitaire', 'photo' => 'Photo', 'subtotal' => 'Sous-total', 'total_word' => 'Total',
            'cart_empty' => 'Ouvrez une commande et ajoutez des produits.',
        ],
    ];

    /**
     * Word fragments (ASCII, regex alternatives) the heuristics match on, per
     * locale. A category missing for a locale falls back to English.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const VOCAB = [
        'en' => [
            'quantity' => ['qty', 'quantity', 'count', '\bunits?\b'],
            'image' => ['image', 'photo', 'picture', 'thumbnail', 'avatar', 'url'],
            'amount' => ['subtotal', 'amount', 'total'],
            'unit_price' => ['\bunit', 'price'],
            'price' => ['price', '\brate\b', 'pvp', 'amount', 'subtotal', 'total'],
            'not_price' => ['budget', 'cost', 'salary', 'wage', 'estimate', 'funding', '\bspend\b', 'expens'],
            'commerce' => ['order', '\bsale\b', 'invoice', '\bcart\b', 'checkout', 'ticket', 'receipt', 'purchase', '\bpos\b', '\bbill\b', 'product', 'item', '\bsku\b', 'catalog', 'menu', '\bdish\b', 'service', '\bline\b'],
            'temporal' => ['label', 'bucket', 'period', 'week'],
            // A field that names where a record IS in its life.
            'status' => ['status', '\bstate\b', 'stage', 'phase', 'progress'],
            // A field that names what a record IS. Looks identical in the
            // schema (a select with options) and must never be defaulted or
            // turned into a board: a classification does not move.
            'not_status' => ['type', 'category', 'kind', 'class', 'speciality', 'specialty', 'segment', 'tier', 'plan', 'brand', 'model', 'source', 'channel', 'method', 'currency', 'country', 'region', 'language', 'role', 'department'],
            // Option labels that read as a lifecycle rather than a taxonomy.
            'lifecycle' => ['\bnew\b', 'open', 'pending', 'progress', 'review', 'waiting', 'blocked', 'hold', 'draft', 'todo', 'doing', 'backlog', 'done', 'closed', 'resolved', 'complete', 'cancel', 'reject', 'approved', 'shipped', 'delivered', 'paid', 'active', 'archived', '\bwon\b', '\blost\b'],
            // A date you look FORWARD to and would want on a calendar.
            'schedule' => ['\bdue\b', 'deadline', 'scheduled', 'appointment', 'expir', 'renewal', 'valid until', 'target', 'reminder', 'follow.?up', 'next\b'],
            // An object that IS a schedule: any date on it is one you look at.
            'event_object' => ['event', 'appointment', 'booking', 'reservation', 'meeting', 'session', 'shift', 'visit', 'agenda', 'calendar', 'schedule', 'class', 'webinar'],
            'span_start' => ['start', 'begin', 'opened', 'opening', '\bfrom\b', 'kickoff'],
            'span_end' => ['\bend\b', 'finish', 'closed', 'closing', 'completion', '\bto\b', '\bdue\b', 'delivery', 'deadline'],
        ],
        'es' => [
            'quantity' => ['cant', 'unidad', 'piezas', 'qty', 'count'],
            'image' => ['imagen', 'foto', 'url', 'avatar'],
            'amount' => ['subtotal', 'importe', '\bmonto\b', 'total'],
            'unit_price' => ['unitario', 'precio'],
            'price' => ['precio', 'tarifa', 'importe', 'pvp', 'subtotal', 'total'],
            'not_price' => ['presupuest', 'costo', 'coste', 'salar', 'sueldo', 'estimad', 'fondo', 'gasto'],
            'commerce' => ['pedido', 'venta', 'factura', 'comanda', 'carrito', 'ticket', 'recibo', 'compra', 'cuenta', 'producto', 'articulo', 'platillo', 'plato', 'menu', 'servicio', 'renglon', 'linea', 'partida', 'detalle'],
            'temporal' => ['label', 'bucket', 'period', 'semana'],
            'status' => ['estado', 'estatus', 'etapa', 'fase', 'situacion', 'avance'],
            'not_status' => ['tipo', 'categoria', 'clase', 'especialidad', 'segmento', 'nivel', 'plan', 'marca', 'modelo', 'origen', 'canal', 'metodo', 'moneda', 'pais', 'region', 'idioma', '\brol\b', 'puesto', 'area', 'departamento', 'giro'],
            'lifecycle' => ['nuevo', 'abiert', 'pendiente', 'proceso', 'curso', 'revision', 'espera', 'bloquead', 'borrador', 'pordoing', 'hacer', 'haciendo', 'termin', 'cerrad', 'resuelt', 'complet', 'cancelad', 'rechazad', 'aprobad', 'enviad', 'entregad', 'pagad', 'activo', 'archivad', 'ganad', 'perdid', 'diagnosticad', 'ejecucion'],
            'schedule' => ['compromiso', 'vencimiento', 'vence', 'limite', 'programad', 'agendad', 'cita', 'caduca', 'renovacion', 'vigencia', 'proxima', 'recordatorio', 'seguimiento', 'entrega'],
            'event_object' => ['evento', 'cita', 'reserva', 'reunion', 'sesion', 'turno', 'visita', 'agenda', 'calendario', 'clase', 'consulta', 'guardia'],
            'span_start' => ['inicio', 'comienzo', 'apertura', 'alta', 'desde', 'arranque'],
            'span_end' => ['\bfin\b', 'final', 'cierre', 'termino', 'entrega', 'hasta', 'vencimiento', 'compromiso'],
        ],
        'pt' => [
            'quantity' => ['qtd', 'quantidade', 'unidade', 'pecas', 'contagem'],
            'image' => ['imagem', 'foto', 'url', 'avatar'],
            'amount' => ['subtotal', 'valor', 'total'],
            'unit_price' => ['unitario', 'preco'],
            'price' => ['preco', 'tarifa', 'valor', 'subtotal', 'total'],
            'not_price' => ['orcamento', 'custo', 'salario', 'estimativa', 'fundo', 'gasto', 'despesa'],
            'commerce' => ['pedido', 'venda', 'fatura', 'comanda', 'carrinho', 'ticket', 'recibo', 'compra', 'conta', 'produto', 'artigo', 'prato', 'cardapio', 'servico', 'linha', 'item'],
            'temporal' => ['label', 'bucket', 'period', 'semana'],
            'status' => ['estado', 'situacao', 'etapa', 'fase', 'andamento'],
            'not_status' => ['tipo', 'categoria', 'classe', 'especialidade', 'segmento', 'nivel', 'plano', 'marca', 'modelo', 'origem', 'canal', 'metodo', 'moeda', 'pais', 'regiao', 'idioma', 'papel', 'cargo', 'area', 'departamento'],
            'lifecycle' => ['novo', 'abert', 'pendente', 'andamento', 'revisao', 'espera', 'bloquead', 'rascunho', 'fazer', 'fazendo', 'concluid', 'fechad', 'resolvid', 'complet', 'cancelad', 'rejeitad', 'aprovad', 'enviad', 'entregue', 'pago', 'ativo', 'arquivad', 'ganho', 'perdid'],
            'schedule' => ['prazo', 'vencimento', 'vence', 'limite', 'agendad', 'programad', 'consulta', 'renovacao', 'validade', 'proxima', 'lembrete', 'entrega'],
            'event_object' => ['evento', 'consulta', 'reserva', 'reuniao', 'sessao', 'turno', 'visita', 'agenda', 'calendario', 'aula'],
            'span_start' => ['inicio', 'comeco', 'abertura', 'desde'],
            'span_end' => ['\bfim\b', 'final', 'fechamento', 'termino', 'entrega', 'ate', 'prazo'],
        ],
        'fr' => [
            'quantity' => ['qte', 'quantite', 'unite', 'pieces', 'nombre'],
            'image' => ['image', 'photo', 'url', 'avatar', 'vignette'],
            'amount' => ['sous-total', 'sous_total', 'montant', 'total'],
            'unit_price' => ['unitaire', 'prix'],
            'price' => ['prix', 'tarif', 'montant', 'total'],
            'not_price' => ['budget', 'cout', 'salaire', 'estimation', 'depense', 'financement'],
            'commerce' => ['commande', 'vente', 'facture', 'panier', 'ticket', 'recu', 'achat', 'produit', 'article', '\bplat', 'menu', 'service', 'ligne', 'addition'],
            'temporal' => ['label', 'bucket', 'period', 'semaine'],
            'status' => ['statut', '\betat\b', 'etape', 'phase', 'avancement'],
            'not_status' => ['type', 'categorie', 'classe', 'specialite', 'segment', 'niveau', 'plan', 'marque', 'modele', 'origine', 'canal', 'methode', 'devise', 'pays', 'region', 'langue', 'role', 'poste', 'service', 'departement'],
            'lifecycle' => ['nouveau', 'ouvert', 'attente', 'cours', 'revision', 'bloque', 'brouillon', 'faire', 'termine', 'ferme', 'resolu', 'complet', 'annule', 'rejete', 'approuve', 'expedie', 'livre', 'paye', 'actif', 'archive', 'gagne', 'perdu'],
            'schedule' => ['echeance', 'expiration', 'expire', 'limite', 'planifie', 'programme', 'rendez', 'renouvellement', 'validite', 'prochaine', 'rappel', 'livraison'],
            'event_object' => ['evenement', 'rendez', 'reservation', 'reunion', 'seance', 'visite', 'agenda', 'calendrier', 'cours'],
            'span_start' => ['debut', 'ouverture', 'depuis', 'demarrage'],
            'span_end' => ['\bfin\b', 'cloture', 'fermeture', 'livraison', 'echeance', 'jusqu'],
        ],
    ];

    /**
     * Dashboard narrative: a caption per chart type and per KPI aggregation, plus
     * the time-bucket adverb — full sentences with {measure}/{dim}/{x}/{series}/
     * {bucket} placeholders. Chart templates position {bucket} per each language's
     * grammar (English parenthesises it, Romance languages fold it into the verb);
     * an absent bucket collapses to nothing.
     *
     * @var array<string, array<string, string>>
     */
    private const CHART = [
        'en' => [
            'pareto' => '{measure} by {dim}, largest first, with the cumulative-% line — where the total concentrates.',
            'pie' => 'Share of {measure} by {dim} over the total.',
            'treemap' => 'Relative weight of {measure} by {dim}; area is share.',
            'hbar' => 'Ranking of {dim} by {measure}, largest first.',
            'line' => 'Evolution of {measure} ({bucket}) over the selected window.',
            'scatter' => 'Relationship between {x} and {measure}; each dot is one record.',
            'box' => 'Distribution of {measure} per {dim}: Q1–Q3 box, median line, outlier dots.',
            'sankey' => 'Flow from {dim} to {series}; ribbon width is volume.',
            'radar' => 'Profile across {dim} on radial axes.',
            'default_dim' => 'Comparison of {measure} across {dim}.',
            'default_nodim' => 'Evolution of {measure} ({bucket}) over the selected window.',
        ],
        'es' => [
            'pareto' => '{measure} por {dim}, de mayor a menor, con la línea de % acumulado — dónde se concentra el total.',
            'pie' => 'Participación de {measure} por {dim} sobre el total.',
            'treemap' => 'Peso relativo de {measure} por {dim}; el área es la proporción.',
            'hbar' => 'Ranking de {dim} por {measure}, de mayor a menor.',
            'line' => 'Evolución {bucket} de {measure} en la ventana seleccionada.',
            'scatter' => 'Relación entre {x} y {measure}; cada punto es un registro.',
            'box' => 'Distribución de {measure} por {dim}: caja Q1–Q3, línea en la mediana, puntos atípicos.',
            'sankey' => 'Flujo de {dim} hacia {series}; el grosor de la cinta es el volumen.',
            'radar' => 'Perfil comparado por {dim} en ejes radiales.',
            'default_dim' => 'Comparación de {measure} entre {dim}.',
            'default_nodim' => 'Evolución {bucket} de {measure} en la ventana seleccionada.',
        ],
        'pt' => [
            'pareto' => '{measure} por {dim}, do maior ao menor, com a linha de % acumulada — onde o total se concentra.',
            'pie' => 'Participação de {measure} por {dim} sobre o total.',
            'treemap' => 'Peso relativo de {measure} por {dim}; a área é a proporção.',
            'hbar' => 'Ranking de {dim} por {measure}, do maior ao menor.',
            'line' => 'Evolução {bucket} de {measure} na janela selecionada.',
            'scatter' => 'Relação entre {x} e {measure}; cada ponto é um registro.',
            'box' => 'Distribuição de {measure} por {dim}: caixa Q1–Q3, linha na mediana, pontos atípicos.',
            'sankey' => 'Fluxo de {dim} para {series}; a espessura da faixa é o volume.',
            'radar' => 'Perfil comparado por {dim} em eixos radiais.',
            'default_dim' => 'Comparação de {measure} entre {dim}.',
            'default_nodim' => 'Evolução {bucket} de {measure} na janela selecionada.',
        ],
        'fr' => [
            'pareto' => '{measure} par {dim}, du plus grand au plus petit, avec la courbe du % cumulé — où le total se concentre.',
            'pie' => 'Part de {measure} par {dim} sur le total.',
            'treemap' => 'Poids relatif de {measure} par {dim} ; l’aire représente la part.',
            'hbar' => 'Classement des {dim} par {measure}, du plus grand au plus petit.',
            'line' => 'Évolution {bucket} de {measure} sur la fenêtre sélectionnée.',
            'scatter' => 'Relation entre {x} et {measure} ; chaque point est un enregistrement.',
            'box' => 'Distribution de {measure} par {dim} : boîte Q1–Q3, ligne médiane, points atypiques.',
            'sankey' => 'Flux de {dim} vers {series} ; la largeur du ruban est le volume.',
            'radar' => 'Profil par {dim} sur des axes radiaux.',
            'default_dim' => 'Comparaison de {measure} entre {dim}.',
            'default_nodim' => 'Évolution {bucket} de {measure} sur la fenêtre sélectionnée.',
        ],
    ];

    /**
     * A short KPI-card subtitle per aggregation — names the number's KIND
     * (count/sum/avg/percentile), never a value that would go stale on filter.
     *
     * @var array<string, array<string, string>>
     */
    private const KPI = [
        'en' => ['count' => 'count in window', 'sum' => 'total in window', 'avg' => 'period average', 'median' => 'period median', 'p90' => 'period p90', 'p95' => 'period p95', 'min' => 'period minimum', 'max' => 'period maximum', 'distinct_count' => 'distinct values'],
        'es' => ['count' => 'conteo en la ventana', 'sum' => 'acumulado en la ventana', 'avg' => 'promedio del periodo', 'median' => 'mediana del periodo', 'p90' => 'percentil 90 del periodo', 'p95' => 'percentil 95 del periodo', 'min' => 'mínimo del periodo', 'max' => 'máximo del periodo', 'distinct_count' => 'valores distintos'],
        'pt' => ['count' => 'contagem na janela', 'sum' => 'acumulado na janela', 'avg' => 'média do período', 'median' => 'mediana do período', 'p90' => 'percentil 90 do período', 'p95' => 'percentil 95 do período', 'min' => 'mínimo do período', 'max' => 'máximo do período', 'distinct_count' => 'valores distintos'],
        'fr' => ['count' => 'total sur la fenêtre', 'sum' => 'cumul sur la fenêtre', 'avg' => 'moyenne de la période', 'median' => 'médiane de la période', 'p90' => 'p90 de la période', 'p95' => 'p95 de la période', 'min' => 'minimum de la période', 'max' => 'maximum de la période', 'distinct_count' => 'valeurs distinctes'],
    ];

    /** The time-bucket adverb per grain, per locale. */
    private const BUCKET = [
        'en' => ['day' => 'daily', 'week' => 'weekly', 'month' => 'monthly', 'quarter' => 'quarterly', 'year' => 'yearly'],
        'es' => ['day' => 'diaria', 'week' => 'semanal', 'month' => 'mensual', 'quarter' => 'trimestral', 'year' => 'anual'],
        'pt' => ['day' => 'diária', 'week' => 'semanal', 'month' => 'mensal', 'quarter' => 'trimestral', 'year' => 'anual'],
        'fr' => ['day' => 'quotidienne', 'week' => 'hebdomadaire', 'month' => 'mensuelle', 'quarter' => 'trimestrielle', 'year' => 'annuelle'],
    ];

    /** The word for "records" when a chart has no explicit measure field, per locale. */
    private const RECORDS = ['en' => 'records', 'es' => 'registros', 'pt' => 'registros', 'fr' => 'enregistrements'];

    private function __construct(private readonly string $locale) {}

    public static function for(?string $locale): self
    {
        return new self(self::resolve($locale));
    }

    /** The supported locale key a raw locale string maps to (its 2-letter prefix, else 'en'). */
    public static function resolve(?string $locale): string
    {
        $prefix = strtolower(substr((string) $locale, 0, 2));

        return in_array($prefix, self::SUPPORTED, true) ? $prefix : 'en';
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * A chrome label with `{s}` (singular) and `{n}` (object name) filled in.
     * Falls back to the English template when the locale lacks the key.
     */
    public function label(string $key, string $singular = '', string $name = '', string $field = ''): string
    {
        $template = self::LABELS[$this->locale][$key] ?? self::LABELS['en'][$key] ?? $key;

        return strtr($template, ['{s}' => $singular, '{n}' => $name, '{f}' => $field]);
    }

    /**
     * Whether any of the locale's words for $category appear in the haystacks
     * (accent-insensitive, case-insensitive). Falls back to English words when the
     * locale has none for that category.
     */
    public function matches(string $category, string ...$haystacks): bool
    {
        $words = self::VOCAB[$this->locale][$category] ?? self::VOCAB['en'][$category] ?? [];
        if ($words === []) {
            return false;
        }

        $haystack = Str::ascii(mb_strtolower(implode(' ', $haystacks)));

        return preg_match('/'.implode('|', $words).'/i', $haystack) === 1;
    }

    /** The KPI-card subtitle for an aggregation (falls back to English, then ''). */
    public function kpiSubtitle(string $aggregation): string
    {
        return self::KPI[$this->locale][$aggregation] ?? self::KPI['en'][$aggregation] ?? '';
    }

    /**
     * The one-line caption under a chart, written from its type and the resolved
     * field names (any of which may be null when the chart doesn't use them).
     * $bucketGrain is the time bucket (day/week/…) for temporal charts, or null.
     */
    public function chartDescription(string $chartType, ?string $measure, ?string $dim, ?string $x, ?string $series, ?string $bucketGrain): string
    {
        $key = match ($chartType) {
            'donut', 'pie' => 'pie',
            'area', 'line' => 'line',
            'pareto', 'treemap', 'hbar', 'scatter', 'box', 'sankey', 'radar' => $chartType,
            default => $dim !== null && $dim !== '' ? 'default_dim' : 'default_nodim',
        };

        $table = self::CHART[$this->locale] ?? self::CHART['en'];
        $template = $table[$key] ?? self::CHART['en'][$key] ?? '';

        $bucket = $bucketGrain !== null
            ? (self::BUCKET[$this->locale][$bucketGrain] ?? self::BUCKET['en'][$bucketGrain] ?? '')
            : '';

        $out = strtr($template, [
            '{measure}' => ($measure !== null && $measure !== '') ? $measure : (self::RECORDS[$this->locale] ?? 'records'),
            '{dim}' => (string) $dim,
            '{x}' => (string) $x,
            '{series}' => (string) $series,
            '{bucket}' => $bucket,
        ]);

        // Drop an empty "()" left by a missing bucket, then collapse whitespace.
        return trim((string) preg_replace('/\s{2,}/', ' ', str_replace('()', '', $out)));
    }
}
