<?php

namespace App\Services\Builder;

use Illuminate\Support\Str;

/**
 * Infers the BUSINESS domain of a dashboard from its objects' vocabulary, so
 * the recommender can (a) prioritise the analyses that matter in that industry
 * and (b) name things in the domain's language. Deterministic keyword scoring
 * over object/field names — no model call. Falls back to `general` when nothing
 * clears the noise floor.
 *
 * The sectors mirror the dashboard blueprint catalog so a future blueprint
 * lookup can key off the same value.
 */
class DomainClassifier
{
    /**
     * sector => [keyword stems that vote for it]. Stems are matched as
     * substrings against the asciified object/field vocabulary (ES + EN).
     *
     * @var array<string, list<string>>
     */
    private const SIGNALS = [
        'support' => [
            'ticket', 'backlog', 'reopen', 'reabiert', 'fcr', 'sla', 'resolucion', 'resolution',
            'motivo', 'reason', 'causa', 'cause', 'agente', 'agent', 'queja', 'complaint',
            'csat', 'satisfac', 'soporte', 'support', 'helpdesk',
        ],
        'sales_crm' => [
            'deal', 'pipeline', 'lead', 'oportunidad', 'opportunit', 'forecast', 'vendedor',
            'seller', 'cierre', 'cuota', 'quota', 'win', 'crm', 'prospect', 'etapa', 'stage',
        ],
        'ecommerce_retail' => [
            'order', 'pedido', 'cart', 'carrito', 'sku', 'producto', 'product', 'inventory',
            'inventario', 'stock', 'envio', 'shipment', 'shipping', 'devoluc', 'refund', 'checkout', 'retail',
        ],
        'saas_subscriptions' => [
            'subscription', 'suscripcion', 'mrr', 'arr', 'churn', 'abandono', 'plan', 'trial',
            'seat', 'retencion', 'retention', 'activacion', 'activation', 'usage', 'uso',
        ],
    ];

    /**
     * Headline terms per sector — a candidate whose measure/dimension names one
     * of these ranks higher (the analyses an operator in that domain opens
     * first). Substrings, asciified.
     *
     * @var array<string, list<string>>
     */
    private const HEADLINE = [
        'support' => ['fcr', 'backlog', 'reopen', 'reabiert', 'motivo', 'reason', 'sla', 'resolucion'],
        'sales_crm' => ['revenue', 'ingreso', 'forecast', 'pipeline', 'deal', 'cierre', 'win'],
        'ecommerce_retail' => ['order', 'pedido', 'revenue', 'ingreso', 'refund', 'devoluc', 'sku', 'envio'],
        'saas_subscriptions' => ['mrr', 'arr', 'churn', 'abandono', 'retencion', 'activo', 'usage'],
        'general' => [],
    ];

    /**
     * Complementary sources that would enrich the analysis for each domain —
     * the standard views an analyst in that industry expects alongside what's
     * already connected. Advisory (each names a source + why); connecting one
     * is a separate integration step.
     *
     * @var array<string, list<array{es: string, en: string, why_es: string, why_en: string}>>
     */
    private const SOURCE_IDEAS = [
        'support' => [
            ['es' => 'Satisfacción (CSAT/NPS) por agente', 'en' => 'Satisfaction (CSAT/NPS) by agent', 'why_es' => 'Cruza calidad percibida con volumen y FCR.', 'why_en' => 'Ties perceived quality to volume and FCR.'],
            ['es' => 'Cumplimiento de SLA', 'en' => 'SLA compliance', 'why_es' => 'Cuántos tickets se resuelven dentro del tiempo pactado.', 'why_en' => 'How many tickets resolve within the agreed time.'],
            ['es' => 'Tiempo de primera respuesta', 'en' => 'First response time', 'why_es' => 'El otro lado del FCR: qué tan rápido atiendes.', 'why_en' => 'The other side of FCR: how fast you engage.'],
        ],
        'sales_crm' => [
            ['es' => 'Tasa de conversión por etapa', 'en' => 'Stage conversion rate', 'why_es' => 'Dónde se cae el pipeline, no solo cuánto entra.', 'why_en' => 'Where the pipeline leaks, not just what enters.'],
            ['es' => 'Ciclo de venta (días a cierre)', 'en' => 'Sales cycle (days to close)', 'why_es' => 'Qué tan rápido madura cada oportunidad.', 'why_en' => 'How fast each opportunity matures.'],
            ['es' => 'Pronóstico vs. real', 'en' => 'Forecast vs. actual', 'why_es' => 'Qué tan confiable es tu forecast.', 'why_en' => 'How reliable your forecast is.'],
        ],
        'ecommerce_retail' => [
            ['es' => 'Tasa de devoluciones por producto', 'en' => 'Return rate by product', 'why_es' => 'Qué SKUs cuestan más después de la venta.', 'why_en' => 'Which SKUs cost most after the sale.'],
            ['es' => 'Ticket promedio por canal', 'en' => 'Average order value by channel', 'why_es' => 'Dónde compra más caro tu cliente.', 'why_en' => 'Where your customer spends more.'],
            ['es' => 'Tiempo de entrega', 'en' => 'Delivery time', 'why_es' => 'El factor de satisfacción más ignorado.', 'why_en' => 'The most overlooked satisfaction driver.'],
        ],
        'saas_subscriptions' => [
            ['es' => 'Churn por cohorte', 'en' => 'Churn by cohort', 'why_es' => 'Retención real por mes de alta.', 'why_en' => 'Real retention by signup month.'],
            ['es' => 'Uso del producto por cuenta', 'en' => 'Product usage by account', 'why_es' => 'La señal temprana de abandono.', 'why_en' => 'The early signal of churn.'],
            ['es' => 'Expansión (upsell/downsell)', 'en' => 'Expansion (upsell/downsell)', 'why_es' => 'El crecimiento que no viene de nuevos clientes.', 'why_en' => 'Growth that isn\'t from new customers.'],
        ],
        'general' => [
            ['es' => 'Una serie de tiempo', 'en' => 'A time series', 'why_es' => 'Una fecha convierte cualquier métrica en tendencia.', 'why_en' => 'A date turns any metric into a trend.'],
            ['es' => 'Una dimensión para segmentar', 'en' => 'A dimension to segment by', 'why_es' => 'Categoría, canal o región abren el desglose.', 'why_en' => 'Category, channel or region open the breakdown.'],
        ],
    ];

    private const LABELS = [
        'support' => ['es' => 'Soporte de tickets', 'en' => 'Customer support'],
        'sales_crm' => ['es' => 'Ventas / CRM', 'en' => 'Sales / CRM'],
        'ecommerce_retail' => ['es' => 'E-commerce / Retail', 'en' => 'E-commerce / Retail'],
        'saas_subscriptions' => ['es' => 'SaaS / Suscripciones', 'en' => 'SaaS / Subscriptions'],
        'general' => ['es' => 'General', 'en' => 'General'],
    ];

    /**
     * @param  list<array<string, mixed>>  $objects  manifest object nodes
     * @return array{sector: string, label: string, headline: list<string>}
     */
    public function classify(array $objects, string $lang = 'es'): array
    {
        $haystack = $this->vocabulary($objects);

        $scores = [];
        foreach (self::SIGNALS as $sector => $stems) {
            $scores[$sector] = collect($stems)
                ->filter(fn (string $stem): bool => str_contains($haystack, $stem))
                ->count();
        }
        arsort($scores);
        $sector = (string) array_key_first($scores);
        // Need a couple of independent signals before claiming a domain — one
        // stray word ("order" in a note) shouldn't rebrand the whole board.
        if (($scores[$sector] ?? 0) < 2) {
            $sector = 'general';
        }

        return [
            'sector' => $sector,
            'label' => self::LABELS[$sector][$lang] ?? self::LABELS[$sector]['en'],
            'headline' => self::HEADLINE[$sector] ?? [],
        ];
    }

    /**
     * Complementary sources worth connecting to enrich the analysis for this
     * domain — advisory hints for the "fuentes leídas" panel.
     *
     * @param  array{sector: string}  $domain
     * @return list<array{title: string, why: string}>
     */
    public function sourceSuggestions(array $domain, string $lang = 'es'): array
    {
        $ideas = self::SOURCE_IDEAS[$domain['sector']] ?? self::SOURCE_IDEAS['general'];

        return collect($ideas)->map(fn (array $i): array => [
            'title' => $lang === 'en' ? $i['en'] : $i['es'],
            'why' => $lang === 'en' ? $i['why_en'] : $i['why_es'],
        ])->all();
    }

    /**
     * Does a field/label name a headline concept for this domain?
     */
    public function isHeadline(array $domain, string $name): bool
    {
        $hay = Str::lower(Str::ascii($name));

        return collect($domain['headline'] ?? [])
            ->contains(fn (string $stem): bool => str_contains($hay, $stem));
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     */
    private function vocabulary(array $objects): string
    {
        $parts = [];
        foreach ($objects as $object) {
            if (! is_array($object)) {
                continue;
            }
            $parts[] = (string) ($object['name'] ?? '');
            $parts[] = (string) ($object['slug'] ?? '');
            foreach ($object['fields'] ?? [] as $field) {
                if (is_array($field)) {
                    $parts[] = (string) ($field['name'] ?? '').' '.(string) ($field['slug'] ?? '');
                }
            }
        }

        return Str::lower(Str::ascii(implode(' ', $parts)));
    }
}
