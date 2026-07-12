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
