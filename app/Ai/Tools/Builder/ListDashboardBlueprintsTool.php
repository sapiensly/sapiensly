<?php

namespace App\Ai\Tools\Builder;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Curated, sector-specific dashboard blueprints. The generic component catalog
 * (list_available_components) tells the model WHICH blocks exist; this tool
 * tells it WHAT an expert analyst in a given sector actually puts on the
 * dashboard — the named KPIs, the right charts, the conclusions worth calling
 * out — so a generated dashboard reads as domain-specialised rather than a
 * generic "count + sum + a couple of bar charts".
 *
 * The blueprints are SEMANTIC, not literal: each KPI/chart names the kind of
 * field + aggregation it needs (a currency measure, a status single_select, a
 * date for bucketing). The model maps those hints to the app's REAL object/
 * field ids (learned via inspect_records / read_manifest) before authoring the
 * metric_grid / chart / insight blocks. Hard-coded so the model cannot invent a
 * sector that has no curated guidance.
 */
class ListDashboardBlueprintsTool implements Tool
{
    /**
     * @var array<string, array{
     *     name: string,
     *     for: string,
     *     kpis: list<array{label: string, aggregation: string, field_hint: string, format: string, delta_good?: string, compare?: string, icon: string}>,
     *     charts: list<array{label: string, chart_type: string, group_by_hint: string, aggregation: string, field_hint?: string, why: string}>,
     *     funnel?: list<string>,
     *     insights: list<array{variant: string, title: string, what: string}>,
     *     layout: string
     * }>
     */
    private const BLUEPRINTS = [
        'support' => [
            'name' => 'Autonomous Customer Support',
            'for' => 'Ticket / conversation desks: support queues, help desks, complaint tracking, SLA-driven service. The Sapiensly MVP sector.',
            'kpis' => [
                ['label' => 'Open tickets', 'aggregation' => 'count', 'field_hint' => 'count of records whose status single_select is an open/in-progress state (filter status in [abierto, en_proceso])', 'format' => 'number', 'delta_good' => 'down', 'compare' => 'previous period via sys_created_at filter', 'icon' => 'inbox'],
                ['label' => 'Resolved this period', 'aggregation' => 'count', 'field_hint' => 'count of records with status = resolved/closed', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'check-circle'],
                ['label' => 'Median resolution time', 'aggregation' => 'median', 'field_hint' => 'a number/duration field holding hours-to-resolve (median is robust to a few very slow outliers — prefer it over avg)', 'format' => 'duration', 'delta_good' => 'down', 'compare' => 'previous period', 'icon' => 'clock'],
                ['label' => 'P95 resolution time', 'aggregation' => 'p95', 'field_hint' => 'same hours-to-resolve field — the worst-case tail 95% of tickets beat, the real SLA story', 'format' => 'duration', 'delta_good' => 'down', 'compare' => 'previous period', 'icon' => 'gauge'],
                ['label' => 'Unique requesters', 'aggregation' => 'distinct_count', 'field_hint' => 'the customer/email/requester field — how many distinct people contacted support', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'users'],
                ['label' => 'CSAT / avg. rating', 'aggregation' => 'avg', 'field_hint' => 'a rating field on the ticket/survey', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'star'],
                ['label' => 'Auto-resolved by agent', 'aggregation' => 'count', 'field_hint' => 'count where a boolean/single_select marks AI-handled (no human touched it) — the squad value metric', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'bot'],
                ['label' => 'SLA breaches', 'aggregation' => 'count', 'field_hint' => 'count where an sla_breached boolean is true (or due_at < now and still open)', 'format' => 'number', 'delta_good' => 'down', 'compare' => 'previous period', 'icon' => 'alert-triangle'],
            ],
            'charts' => [
                ['label' => 'Tickets over time', 'chart_type' => 'line', 'group_by_hint' => 'sys_created_at (the runtime buckets a date group by day/week)', 'aggregation' => 'count', 'why' => 'volume trend — spot spikes and seasonality'],
                ['label' => 'By status', 'chart_type' => 'donut', 'group_by_hint' => 'status single_select', 'aggregation' => 'count', 'why' => 'current queue composition at a glance'],
                ['label' => 'By category / intent', 'chart_type' => 'hbar', 'group_by_hint' => 'category or intent single_select', 'aggregation' => 'count', 'why' => 'what users contact about — hbar handles long labels'],
                ['label' => 'By channel', 'chart_type' => 'bar', 'group_by_hint' => 'channel single_select (whatsapp/widget/email)', 'aggregation' => 'count', 'why' => 'where demand comes from'],
                ['label' => 'Volume vs status by week', 'chart_type' => 'bar', 'group_by_hint' => 'week of sys_created_at, split by status (series_field_id=status, stacked:true)', 'aggregation' => 'count', 'why' => 'resolution keeping up with inflow?'],
            ],
            'insights' => [
                ['variant' => 'conclusion', 'title' => 'Resolution rate vs inflow', 'what' => 'State whether resolved >= created this period; if backlog is growing, say so with the delta.'],
                ['variant' => 'recommendation', 'title' => 'Top contact reason', 'what' => 'Name the #1 category and recommend a deflection action (FAQ/knowledge article / automation).'],
                ['variant' => 'risk', 'title' => 'SLA risk', 'what' => 'Call out open tickets near/over SLA; only show when the SLA-breach count is non-zero (visibility/expression).'],
            ],
            'layout' => 'KPI row first (metric_grid, 6 items, each with compare + delta_good). Then a 2-col row: "Tickets over time" (line) beside "By status" (donut). Then "By category" (hbar) beside "By channel" (bar). Then the stacked volume-vs-status bar full width. Finish with the three insight cards stacked. Resolution time / SLA / open-tickets are GOOD when DOWN — set delta_good:"down" so the trend chip colours correctly.',
        ],

        'sales_crm' => [
            'name' => 'Sales / CRM Pipeline',
            'for' => 'Deals, leads, opportunities, accounts — anything with a value, an owner and a stage.',
            'kpis' => [
                ['label' => 'Pipeline value', 'aggregation' => 'sum', 'field_hint' => 'currency amount over open deals (filter stage not in [won, lost])', 'format' => 'currency', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'trending-up'],
                ['label' => 'Won this period', 'aggregation' => 'sum', 'field_hint' => 'currency amount where stage = won, filtered to the period', 'format' => 'currency', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'trophy'],
                ['label' => 'Win rate', 'aggregation' => 'count', 'field_hint' => 'a RATIO: set the KPI query to won deals (count) and ratio_denominator to closed deals (won+lost, count); format percentage', 'format' => 'percentage', 'delta_good' => 'up', 'icon' => 'percent'],
                ['label' => 'Avg. deal size', 'aggregation' => 'avg', 'field_hint' => 'currency amount over won deals', 'format' => 'currency', 'delta_good' => 'up', 'icon' => 'dollar-sign'],
                ['label' => 'Open deals', 'aggregation' => 'count', 'field_hint' => 'count of deals in an open stage', 'format' => 'number', 'delta_good' => 'up', 'icon' => 'briefcase'],
            ],
            'charts' => [
                ['label' => 'Pipeline by stage', 'chart_type' => 'bar', 'group_by_hint' => 'stage single_select', 'aggregation' => 'sum', 'field_hint' => 'currency amount', 'why' => 'value concentration across the funnel'],
                ['label' => 'Revenue trend', 'chart_type' => 'area', 'group_by_hint' => 'sys_created_at (or a close_date) bucketed by month', 'aggregation' => 'sum', 'field_hint' => 'currency amount', 'why' => 'momentum over time'],
                ['label' => 'By owner', 'chart_type' => 'hbar', 'group_by_hint' => 'owner relation/lookup or a rep single_select', 'aggregation' => 'sum', 'field_hint' => 'currency amount', 'why' => 'rep contribution / leaderboard'],
                ['label' => 'By source', 'chart_type' => 'donut', 'group_by_hint' => 'lead source single_select', 'aggregation' => 'count', 'why' => 'where deals originate'],
            ],
            'funnel' => ['Leads (all)', 'Qualified', 'Proposal', 'Negotiation', 'Won'],
            'insights' => [
                ['variant' => 'conclusion', 'title' => 'Stage conversion', 'what' => 'Identify the biggest drop-off stage from the funnel and quantify it.'],
                ['variant' => 'recommendation', 'title' => 'Where to focus', 'what' => 'Recommend the stage/owner/source with the best ROI to double down on.'],
                ['variant' => 'risk', 'title' => 'Stale pipeline', 'what' => 'Flag value sitting in open deals untouched for too long (sys_updated_at older than N days).'],
            ],
            'layout' => 'KPI row (metric_grid, currency formats). Then the funnel block full width. Then "Pipeline by stage" beside "Revenue trend". Then "By owner" beside "By source". Insight cards last. Use format:"currency" on money KPIs and aggregation sum with the currency field_id.',
        ],

        'ecommerce_retail' => [
            'name' => 'E-commerce / Retail',
            'for' => 'Orders, sales, products, inventory — point of sale and online store data.',
            'kpis' => [
                ['label' => 'Revenue', 'aggregation' => 'sum', 'field_hint' => 'order total currency field over the period', 'format' => 'currency', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'dollar-sign'],
                ['label' => 'Orders', 'aggregation' => 'count', 'field_hint' => 'count of order records in the period', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'shopping-cart'],
                ['label' => 'Average order value', 'aggregation' => 'avg', 'field_hint' => 'order total currency field', 'format' => 'currency', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'receipt'],
                ['label' => 'Units sold', 'aggregation' => 'sum', 'field_hint' => 'quantity number field on order lines (or a units rollup on the order)', 'format' => 'number', 'delta_good' => 'up', 'icon' => 'package'],
                ['label' => 'Low-stock SKUs', 'aggregation' => 'count', 'field_hint' => 'count of products where stock < threshold', 'format' => 'number', 'delta_good' => 'down', 'icon' => 'alert-triangle'],
            ],
            'charts' => [
                ['label' => 'Sales over time', 'chart_type' => 'area', 'group_by_hint' => 'order date / sys_created_at by day', 'aggregation' => 'sum', 'field_hint' => 'order total', 'why' => 'revenue trend & seasonality'],
                ['label' => 'Top products', 'chart_type' => 'hbar', 'group_by_hint' => 'product relation/lookup on order lines', 'aggregation' => 'sum', 'field_hint' => 'line subtotal or quantity', 'why' => 'best sellers — sort the data_source desc and limit'],
                ['label' => 'Revenue by category', 'chart_type' => 'treemap', 'group_by_hint' => 'product category single_select', 'aggregation' => 'sum', 'field_hint' => 'order total / subtotal', 'why' => 'part-to-whole segmentation'],
                ['label' => 'Orders by status', 'chart_type' => 'donut', 'group_by_hint' => 'fulfilment status single_select', 'aggregation' => 'count', 'why' => 'fulfilment health'],
            ],
            'insights' => [
                ['variant' => 'positive', 'title' => 'Best seller', 'what' => 'Name the top product/category by revenue and its share.'],
                ['variant' => 'recommendation', 'title' => 'Restock now', 'what' => 'List low-stock high-velocity SKUs to reorder.'],
                ['variant' => 'conclusion', 'title' => 'Revenue vs last period', 'what' => 'State growth/decline with the % delta from the compare query.'],
            ],
            'layout' => 'KPI row (currency + number). Then "Sales over time" full width. Then "Top products" beside "Revenue by category". Then "Orders by status" beside the insight cards. Low-stock is GOOD when DOWN.',
        ],

        'saas_subscriptions' => [
            'name' => 'SaaS / Subscriptions',
            'for' => 'Subscriptions, accounts, usage, MRR — recurring-revenue products.',
            'kpis' => [
                ['label' => 'MRR', 'aggregation' => 'sum', 'field_hint' => 'monthly amount currency field over active subscriptions', 'format' => 'currency', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'trending-up'],
                ['label' => 'Active subscriptions', 'aggregation' => 'count', 'field_hint' => 'count where status = active', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'users'],
                ['label' => 'New this period', 'aggregation' => 'count', 'field_hint' => 'count created in the period', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'user-plus'],
                ['label' => 'Churned', 'aggregation' => 'count', 'field_hint' => 'count where status = cancelled in the period', 'format' => 'number', 'delta_good' => 'down', 'compare' => 'previous period', 'icon' => 'user-minus'],
                ['label' => 'ARPU', 'aggregation' => 'avg', 'field_hint' => 'monthly amount over active subscriptions', 'format' => 'currency', 'delta_good' => 'up', 'icon' => 'dollar-sign'],
            ],
            'charts' => [
                ['label' => 'MRR growth', 'chart_type' => 'line', 'group_by_hint' => 'sys_created_at by month', 'aggregation' => 'sum', 'field_hint' => 'monthly amount', 'why' => 'the headline recurring-revenue trend'],
                ['label' => 'By plan', 'chart_type' => 'bar', 'group_by_hint' => 'plan single_select', 'aggregation' => 'sum', 'field_hint' => 'monthly amount', 'why' => 'which tiers drive revenue'],
                ['label' => 'Status mix', 'chart_type' => 'donut', 'group_by_hint' => 'subscription status single_select', 'aggregation' => 'count', 'why' => 'active vs trial vs cancelled'],
                ['label' => 'Signups vs churn by month', 'chart_type' => 'bar', 'group_by_hint' => 'month, split by status (series_field_id, stacked)', 'aggregation' => 'count', 'why' => 'net growth visualised'],
            ],
            'insights' => [
                ['variant' => 'conclusion', 'title' => 'Net growth', 'what' => 'New minus churned this period; say whether the base is growing.'],
                ['variant' => 'risk', 'title' => 'Churn watch', 'what' => 'Flag if churn rose vs last period; name the plan losing the most.'],
                ['variant' => 'recommendation', 'title' => 'Upsell lane', 'what' => 'Recommend the plan/segment with the most upgrade headroom.'],
            ],
            'layout' => 'KPI row (MRR/ARPU currency, counts number). Then "MRR growth" full width. Then "By plan" beside "Status mix". Then "Signups vs churn" stacked bar beside insights. Churn is GOOD when DOWN.',
        ],

        'general' => [
            'name' => 'General record analytics',
            'for' => 'Any record-based app that does not match a named sector — derive KPIs from the object\'s own measure + dimension + date fields.',
            'kpis' => [
                ['label' => 'Total records', 'aggregation' => 'count', 'field_hint' => 'count of the main object', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period via sys_created_at', 'icon' => 'database'],
                ['label' => 'New this period', 'aggregation' => 'count', 'field_hint' => 'count filtered to the current period on sys_created_at', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'plus-circle'],
                ['label' => 'Total / sum of <measure>', 'aggregation' => 'sum', 'field_hint' => 'the main numeric/currency field if one exists', 'format' => 'number', 'delta_good' => 'up', 'compare' => 'previous period', 'icon' => 'sigma'],
                ['label' => 'Average <measure>', 'aggregation' => 'avg', 'field_hint' => 'the same numeric field', 'format' => 'number', 'delta_good' => 'up', 'icon' => 'bar-chart'],
            ],
            'charts' => [
                ['label' => 'Over time', 'chart_type' => 'line', 'group_by_hint' => 'sys_created_at by day/week', 'aggregation' => 'count', 'why' => 'growth/activity trend — always available via system fields'],
                ['label' => 'By <category>', 'chart_type' => 'hbar', 'group_by_hint' => 'the main single_select dimension', 'aggregation' => 'count', 'why' => 'composition by the primary category'],
                ['label' => 'Share by <category>', 'chart_type' => 'donut', 'group_by_hint' => 'a secondary single_select', 'aggregation' => 'count', 'why' => 'part-to-whole when categories are few'],
            ],
            'insights' => [
                ['variant' => 'conclusion', 'title' => 'Trend', 'what' => 'State whether volume is rising or falling vs the prior period.'],
                ['variant' => 'insight', 'title' => 'Dominant category', 'what' => 'Name the largest group and its share.'],
            ],
            'layout' => 'KPI row from whatever count/sum/avg the object supports. Then "Over time" full width (always works via sys_created_at). Then category charts side by side. One or two insight cards. Pick charts only for fields that EXIST — verify with inspect_records first.',
        ],
    ];

    public function name(): string
    {
        return 'list_dashboard_blueprints';
    }

    public function description(): string
    {
        $sectors = implode(', ', array_keys(self::BLUEPRINTS));

        return "Get a SECTOR-SPECIALISED dashboard blueprint — the named KPIs, the right charts, and the conclusions an expert analyst in that sector would put on the page. Call this FIRST whenever the user asks for a dashboard, report, analytics, KPIs or \"métricas/tablero\", BEFORE composing metric_grid/chart/insight blocks, so the result is domain-specific instead of generic. Pass `sector` (one of: {$sectors}); omit it to list sectors with one-line descriptions and pick the closest match (use `general` if none fits). The blueprint is SEMANTIC: each KPI/chart names the field KIND + aggregation it needs — map those to the app's REAL field ids (read_manifest / inspect_records) and verify with simulate_query before propose_change.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sector' => $schema->string()
                ->description('Which sector blueprint to fetch: '.implode(', ', array_keys(self::BLUEPRINTS)).'. Omit to list sectors.'),
        ];
    }

    public function handle(Request $request): string
    {
        $sector = strtolower(trim((string) ($request->all()['sector'] ?? '')));

        if ($sector === '') {
            return json_encode([
                'sectors' => array_map(
                    fn (string $key): array => ['sector' => $key, 'name' => self::BLUEPRINTS[$key]['name'], 'for' => self::BLUEPRINTS[$key]['for']],
                    array_keys(self::BLUEPRINTS),
                ),
                'note' => 'Pass one as `sector` to get its blueprint. Use `general` when no named sector fits.',
            ], JSON_THROW_ON_ERROR);
        }

        if (! isset(self::BLUEPRINTS[$sector])) {
            return json_encode([
                'error' => "Unknown sector '{$sector}'.",
                'sectors' => array_keys(self::BLUEPRINTS),
                'note' => 'Use `general` when no named sector fits.',
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'sector' => $sector,
            'blueprint' => self::BLUEPRINTS[$sector],
            'how_to_apply' => self::HOW_TO_APPLY,
        ], JSON_THROW_ON_ERROR);
    }

    private const HOW_TO_APPLY = [
        '1. Profile the data first: call profile_object on the object(s) you will chart (and read_manifest for ids). MAP each KPI/chart field_hint to a REAL field of the matching role (measure/temporal/categorical) from the profile. Skip any KPI/chart whose underlying field does not exist — never invent fields to satisfy the blueprint.',
        '2. Build the KPI row as ONE metric_grid block (not many stat blocks); each item is {id,label,query,aggregation,field_id?,format,icon,compare?,delta_good?}. Use compare (a second query filtered to the previous period via sys_created_at) so every card shows a trend chip.',
        '3. delta_good matters: set "down" for metrics where LESS is better (resolution time, churn, SLA breaches, costs, low stock); "up" otherwise. Wrong direction colours the trend red/green backwards.',
        '4. Charts: one `chart` block each, chart_type + data_source + aggregation as given; put group_by_hint into group_by_field_id (single_select) or x_field_id (a date — the runtime buckets it). For top-N charts, sort the data_source desc and set a limit.',
        '5. Insights: write `insight` blocks whose body states the conclusion FROM the data you saw via simulate_query — real numbers, not placeholders. Use the variant given (conclusion/recommendation/risk/positive). For a figure that must stay current, attach `compute` (query+aggregation+optional compare/delta_good) so the number and its trend chip are LIVE instead of hard-coded. Gate risk cards that should only show when non-zero with a block visibility expression.',
        '6. Verify each aggregation/chart with simulate_query before propose_change; a KPI returning null or a chart with no buckets means the field_id/filter is wrong.',
        '7. Layout: follow the blueprint `layout` — KPI row on top, charts in 2-col `container` (direction=row) or split_view, insight cards to close. Tint the KPI row with var(--sp-accent-50) for an executive look (see framework_reference palette/custom_css).',
    ];
}
