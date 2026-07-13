<?php

namespace App\Ai\Tools\Builder;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The MANDATORY planning stage for dashboards. Before proposing dashboard blocks,
 * the model submits its layout (rows top→bottom, each row's blocks with chart
 * types and column weights) and this tool LINTS it deterministically against the
 * qualities that make a dashboard read as professional: KPI row first,
 * importance-first ordering, coherent titled grouping, balanced rows with no
 * empty gaps, and chart VARIETY (no repeating the same chart everywhere), plus a
 * written-conclusions (insight) requirement. Issues are blocking — the model
 * fixes the plan and re-calls until ok:true, then builds exactly that plan.
 *
 * Deterministic on purpose: prompt guidance can be skipped or drift; a linter
 * cannot. This is the authoring-side counterpart of the runtime layout fixes
 * (equal-height rows, col_span, masonry).
 */
class PlanDashboardTool implements Tool
{
    /** chart_type values the runtime can render (mirrors the manifest schema enum). */
    private const CHART_TYPES = [
        'bar', 'hbar', 'line', 'area', 'pie', 'donut', 'radar', 'scatter',
        'treemap', 'sankey', 'box', 'pareto',
    ];

    /** Block types that belong on a dashboard page plan. */
    private const KNOWN_BLOCKS = [
        'metric_grid', 'chart', 'stat', 'insight', 'table', 'data_grid',
        'pivot', 'sparkline', 'gauge', 'progress', 'heatmap', 'timeline', 'gantt',
        'funnel', 'map', 'word_cloud', 'kanban', 'calendar', 'card_grid',
        'filter_bar', 'heading', 'text', 'markdown',
    ];

    /** Dedicated viz blocks: each is its own visual FORM (counts toward variety). */
    private const VIZ_BLOCKS = [
        'pivot', 'sparkline', 'gauge', 'progress', 'heatmap', 'timeline', 'gantt',
        'funnel', 'map', 'word_cloud', 'kanban', 'calendar', 'card_grid',
    ];

    /** Naturally SHORT blocks — alone in a row they leave it looking empty. */
    private const SHORT_BLOCKS = ['stat', 'gauge', 'progress', 'sparkline', 'badge'];

    private const SHORT_CHARTS = ['pie', 'donut'];

    /** Naturally WIDE/TALL content that deserves the lion's share of a row. */
    private const WIDE_CHARTS = ['line', 'area', 'bar', 'box', 'sankey', 'treemap', 'pareto'];

    public function name(): string
    {
        return 'plan_dashboard';
    }

    public function description(): string
    {
        return <<<'DESC'
MANDATORY before building a dashboard/report page: submit your layout plan and
get it linted for the qualities of a professional dashboard. Pass the purpose
(audience + the questions it answers) and `rows` top→bottom — MOST IMPORTANT
FIRST — each {section?, blocks: [{type, chart_type?, col_span?}]}.

The lints enforce: a full-width KPI `metric_grid` up top; coherent titled
sections; 1-3 blocks per row with no lone short block leaving a gap (pair it
with a companion chart); col_span weights for wide+narrow pairs; chart VARIETY
(never the same chart_type three times, mix forms); and at least one `insight`
card so the dashboard states conclusions, not just charts.

Returns {ok, issues, hints}. Treat `issues` like validation errors — fix the
plan and call again until ok:true, THEN build exactly this plan with
propose_change (sections as headings, rows as direction:"row" containers).
Re-call if you change the layout while building.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'purpose' => $schema->string()
                ->description('Audience + the questions the dashboard answers, and why the top rows are the most important — one or two sentences.')
                ->required(),
            'rows' => $schema->array()
                ->description('The layout, top→bottom, most important first. Each row: {section?: string (title of the group this row belongs to), blocks: [{type: string, chart_type?: string (for type=chart), col_span?: int 1-12 (width weight in the row)}]}. A row\'s blocks render side by side at equal height.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $purpose = trim((string) ($args['purpose'] ?? ''));
        $rows = is_array($args['rows'] ?? null) ? array_values($args['rows']) : [];

        $result = self::lint($purpose, $rows);
        $ok = $result['ok'];

        return json_encode([
            'ok' => $ok,
            'issues' => $result['issues'],
            'hints' => $result['hints'],
            'message' => $ok
                ? 'Plan approved — build EXACTLY this layout with propose_change: sections as headings, each planned row as a direction:"row" container (col_span as planned), KPI band and insights included. Re-call plan_dashboard if the layout changes while building.'
                : 'Fix every issue and call plan_dashboard again — do NOT propose dashboard blocks until the plan returns ok:true.',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The deterministic dashboard-quality lints, reusable outside the tool —
     * the add_dashboard_page compiler runs them over its OWN generated layout
     * so a compiled page is professional by construction, not by prompt.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{ok: bool, issues: list<string>, hints: list<string>}
     */
    public static function lint(string $purpose, array $rows): array
    {
        $issues = [];
        $hints = [];

        if ($purpose === '') {
            $issues[] = 'State the purpose: who reads this dashboard and which questions it answers — it drives what goes first.';
        }
        if ($rows === []) {
            $issues[] = 'The plan has no rows — lay out the page top→bottom, most important first.';
        }

        $chartTypeCounts = [];
        $blockTypesSeen = [];
        $insightCount = 0;
        $metricGridRows = [];
        $sectionCount = 0;

        foreach ($rows as $ri => $row) {
            $rowNo = $ri + 1;
            if (! is_array($row)) {
                $issues[] = "Row {$rowNo} is not an object — each row is {section?, blocks: [...]}.";

                continue;
            }
            if (is_string($row['section'] ?? null) && trim($row['section']) !== '') {
                $sectionCount++;
            }
            $blocks = is_array($row['blocks'] ?? null) ? array_values($row['blocks']) : [];
            if ($blocks === []) {
                $issues[] = "Row {$rowNo} has no blocks.";

                continue;
            }
            if (count($blocks) > 3) {
                $issues[] = "Row {$rowNo} has ".count($blocks).' blocks — more than 3 side by side gets cramped. Split it.';
            }

            $spans = [];
            $rowKinds = [];
            foreach ($blocks as $block) {
                $type = is_array($block) ? (string) ($block['type'] ?? '') : '';
                $chartType = is_array($block) ? (string) ($block['chart_type'] ?? '') : '';
                if (! in_array($type, self::KNOWN_BLOCKS, true)) {
                    $issues[] = "Row {$rowNo} uses unknown block type '{$type}' — plan with real blocks (list_available_components).";

                    continue;
                }
                $blockTypesSeen[$type] = true;
                if ($type === 'insight') {
                    $insightCount++;
                }
                if ($type === 'metric_grid') {
                    $metricGridRows[] = ['row' => $rowNo, 'alone' => count($blocks) === 1];
                }
                if ($type === 'chart') {
                    if ($chartType === '') {
                        $issues[] = "Row {$rowNo}: a chart block needs its chart_type in the plan.";
                    } elseif (! in_array($chartType, self::CHART_TYPES, true)) {
                        $issues[] = "Row {$rowNo}: unknown chart_type '{$chartType}'. Valid: ".implode(', ', self::CHART_TYPES).'.';
                    } else {
                        $chartTypeCounts[$chartType] = ($chartTypeCounts[$chartType] ?? 0) + 1;
                    }
                }
                $spans[] = is_array($block) && is_numeric($block['col_span'] ?? null)
                    ? (int) $block['col_span'] : null;
                $rowKinds[] = self::kindOf($type, $chartType);
            }

            // A lone SHORT block leaves the rest of the row empty.
            if (count($blocks) === 1 && $rowKinds !== [] && $rowKinds[0] === 'short') {
                $issues[] = "Row {$rowNo} is a single short block — the row will look empty. Pair it with a companion chart, stack two short blocks, or fold it into the KPI row.";
            }

            // metric_grid must own its row.
            if (count($blocks) > 1 && in_array('kpi', $rowKinds, true)) {
                $issues[] = "Row {$rowNo}: metric_grid is a full-width KPI band — give it its own row, never beside a chart.";
            }

            // Wide + short pair without weights → suggest 70/30.
            if (count($blocks) === 2
                && in_array('wide', $rowKinds, true) && in_array('short', $rowKinds, true)
                && $spans === [null, null]) {
                $hints[] = "Row {$rowNo} pairs a wide chart with a short one — set col_span (7 and 3, or 8 and 4) so the wide one gets the room and both fill the row at equal height.";
            }
        }

        // KPI band: present, near the top, alone.
        if ($metricGridRows === []) {
            $issues[] = 'No KPI row — open the dashboard with a full-width metric_grid of the headline numbers (with icons, and compare/delta_good where the data allows).';
        } elseif ($metricGridRows[0]['row'] > 2) {
            $issues[] = "The KPI metric_grid sits at row {$metricGridRows[0]['row']} — the headline numbers go FIRST (row 1, or right after a hero heading).";
        }

        // Variety: no chart form repeated to exhaustion; mix forms on chart-heavy pages.
        foreach ($chartTypeCounts as $type => $count) {
            if ($count >= 3) {
                $issues[] = "chart_type '{$type}' appears {$count} times — vary the forms (line/area for trend, donut for share, hbar for rankings, box for distribution, sankey for flow, radar for profiles). Max 2 of a kind.";
            }
        }
        // Dedicated viz blocks (map, gantt, heatmap, funnel, …) are each their own
        // visual form, so they earn variety credit alongside distinct chart_types.
        $dedicatedForms = count(array_intersect(array_keys($blockTypesSeen), self::VIZ_BLOCKS));
        $distinctForms = count($chartTypeCounts) + $dedicatedForms;
        $totalCharts = array_sum($chartTypeCounts);
        if ($totalCharts >= 4 && $distinctForms < 3) {
            $issues[] = "{$totalCharts} charts but only {$distinctForms} distinct visual forms — a professional dashboard mixes forms so each question gets the shape that answers it (also consider the dedicated blocks: map, gantt, heatmap, funnel, gauge, word_cloud…).";
        }

        // Conclusions: numbers need a written reading.
        if ($insightCount === 0) {
            $issues[] = 'No insight cards — a dashboard states conclusions, recommendations and risks (variant conclusion/recommendation/risk/positive), ideally with a live `compute`. Add an insights row.';
        }

        // Grouping: titled sections make the layout read coherently.
        if (count($rows) > 3 && $sectionCount === 0) {
            $hints[] = 'No sections — group related rows under short headings ("Tendencia", "Desglose", "Lecturas clave") so the data reads coherently.';
        }

        return ['ok' => $issues === [], 'issues' => $issues, 'hints' => $hints];
    }

    /** Coarse layout footprint of a block for balance checks. */
    public static function kindOf(string $type, string $chartType): string
    {
        if ($type === 'metric_grid') {
            return 'kpi';
        }
        if ($type === 'chart') {
            if (in_array($chartType, self::SHORT_CHARTS, true)) {
                return 'short';
            }

            return in_array($chartType, self::WIDE_CHARTS, true) ? 'wide' : 'medium';
        }
        if (in_array($type, self::SHORT_BLOCKS, true)) {
            return 'short';
        }
        if (in_array($type, ['table', 'data_grid', 'kanban', 'calendar', 'map', 'gantt', 'pivot'], true)) {
            return 'wide';
        }

        return 'medium';
    }
}
