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
            'by_status' => '{n} by status', 'total' => '{n} total', 'average' => '{n} average',
            'over_time' => '{n} over time', 'value_by_status' => '{n} value by status',
            'open' => 'Open', 'detail' => 'Detail', 'report' => 'Report',
            'trend' => 'Trend', 'breakdown' => 'Breakdown', 'insights' => 'Key readings',
            'pos' => 'Point of Sale', 'new_order' => 'New order', 'order' => 'Order', 'qty' => 'Quantity',
            'unit_price' => 'Unit price', 'subtotal' => 'Subtotal', 'total_word' => 'Total',
            'cart_empty' => 'Open an order and add products.',
        ],
        'es' => [
            'new' => 'Agregar {s}', 'submit' => 'Guardar', 'saved' => 'Guardado', 'created_col' => 'Creado',
            'by_status' => '{n} por estado', 'total' => 'Total {n}', 'average' => 'Promedio {n}',
            'over_time' => '{n} en el tiempo', 'value_by_status' => 'Valor de {n} por estado',
            'open' => 'Abrir', 'detail' => 'Detalle', 'report' => 'Reporte',
            'trend' => 'Tendencia', 'breakdown' => 'Desglose', 'insights' => 'Lecturas clave',
            'pos' => 'Punto de venta', 'new_order' => 'Nueva orden', 'order' => 'Pedido', 'qty' => 'Cantidad',
            'unit_price' => 'Precio unitario', 'subtotal' => 'Subtotal', 'total_word' => 'Total',
            'cart_empty' => 'Abre una orden y agrega productos.',
        ],
        'pt' => [
            'new' => 'Adicionar {s}', 'submit' => 'Salvar', 'saved' => 'Salvo', 'created_col' => 'Criado',
            'by_status' => '{n} por status', 'total' => 'Total de {n}', 'average' => 'Média de {n}',
            'over_time' => '{n} ao longo do tempo', 'value_by_status' => 'Valor de {n} por status',
            'open' => 'Abrir', 'detail' => 'Detalhe', 'report' => 'Relatório',
            'trend' => 'Tendência', 'breakdown' => 'Detalhamento', 'insights' => 'Leituras-chave',
            'pos' => 'Ponto de venda', 'new_order' => 'Novo pedido', 'order' => 'Pedido', 'qty' => 'Quantidade',
            'unit_price' => 'Preço unitário', 'subtotal' => 'Subtotal', 'total_word' => 'Total',
            'cart_empty' => 'Abra um pedido e adicione produtos.',
        ],
        'fr' => [
            'new' => 'Ajouter {s}', 'submit' => 'Enregistrer', 'saved' => 'Enregistré', 'created_col' => 'Créé',
            'by_status' => '{n} par statut', 'total' => 'Total {n}', 'average' => 'Moyenne {n}',
            'over_time' => '{n} dans le temps', 'value_by_status' => 'Valeur de {n} par statut',
            'open' => 'Ouvrir', 'detail' => 'Détail', 'report' => 'Rapport',
            'trend' => 'Tendance', 'breakdown' => 'Répartition', 'insights' => 'Lectures clés',
            'pos' => 'Point de vente', 'new_order' => 'Nouvelle commande', 'order' => 'Commande', 'qty' => 'Quantité',
            'unit_price' => 'Prix unitaire', 'subtotal' => 'Sous-total', 'total_word' => 'Total',
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
        ],
    ];

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
    public function label(string $key, string $singular = '', string $name = ''): string
    {
        $template = self::LABELS[$this->locale][$key] ?? self::LABELS['en'][$key] ?? $key;

        return strtr($template, ['{s}' => $singular, '{n}' => $name]);
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
}
