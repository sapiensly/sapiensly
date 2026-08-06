<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Models\User;
use App\Services\Analyst\GroundTruth;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\DashboardSpecSuggester;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\ObjectRowSource;
use App\Support\Ai\FactGuard;
use App\Support\Branding\ColorPalette;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Compact dashboard compiler: the model declares the CONTENT (KPIs, charts,
 * insights) in one small call and the server composes the whole professional
 * page — balanced rows with column weights, the KPI band, the date-range
 * filter wired into every block, the brand hero, valid ids — then lints its own
 * layout with PlanDashboardTool::lint and submits it through the shared
 * propose_change accumulator (so it banks a checkpoint immediately). Built so a
 * SLOW model finishes a dashboard in ~1 call instead of ~20 hand-written ops.
 */
class AddDashboardPageTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private ProposeChangeTool $proposeTool,
        private AppScaffolder $scaffolder,
        private ?User $actor = null,
    ) {}

    public function name(): string
    {
        return 'add_dashboard_page';
    }

    public function description(): string
    {
        return <<<'DESC'
Build a COMPLETE, professional dashboard page in a single step — USE THIS for
every dashboard/report instead of hand-writing blocks op-by-op. `object_slug`
names the PRIMARY object; any kpi/chart may add its own `object_slug` to read
a DIFFERENT existing object (multi-object boards: e.g. the weekly series from
one object, breakdowns from another — each block's date filter wires to its
own object's date field).
You declare the analytical content; the server compiles the layout (KPI band
first, balanced chart rows with column weights, insights, a Hoy/7d/30d/90d/Año
date-range filter wired into every block, a compact brand hero) and validates
it against the professional-dashboard lints before applying. Pass:
`object_slug` (required); `title?`; `purpose?` (audience + questions answered);
`date_field_id?` (defaults to the object's first date field or sys_created_at);
`kpis` (1-8 of {label, aggregation: count|sum|avg|min|max|distinct_count|median|
p90|p95, field_id? (needed for non-count), format?, icon?, filter?, compare?,
delta_good?}); `charts` (1-10 of {label, chart_type: bar|hbar|line|area|pie|
donut|radar|scatter|treemap|sankey|box|pareto, aggregation: count|sum|avg|min|max,
y_field_id? (needed for sum/avg/min/max), group_by_field_id?, x_field_id?,
bucket?, series_field_id?, stacked?, filter?, limit?}); `insights` (0-4 of
{variant: conclusion|recommendation|risk|positive|insight, title, body?,
compute?, metric_label?} — metric_label is the short unit under a compute's big
figure, e.g. 'semanas'/'pico retrasos'); `include_hero?`; `include_date_filter?`. FAST PATH: pass `use_suggestion: true`
(with `object_slug`) to compile the deterministic spec prepare_dashboard showed
as suggested_spec, plus `overrides` ({title?, purpose?, date_field_id?, kpis?,
charts?, insights?, …} — each key you send REPLACES that part of the
suggestion). Prefer the fast path with small overrides (real insight bodies, a
better title) — your output stays ~100 tokens. Vary chart_type when authoring
manually (never one form 3×; percentiles go in KPIs, not charts; a pie/donut
needs a group_by_field_id to slice by — without one it's a single 100% slice).
Returns {ok, page:{slug, path}} or {ok:false, errors} naming exactly what to fix.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_slug' => $schema->string()
                ->description('Slug of the (existing) object the dashboard reads.')
                ->required(),
            'title' => $schema->string()
                ->description('Dashboard title (defaults to the object name).'),
            'purpose' => $schema->string()
                ->description('Audience + the questions the dashboard answers.'),
            'date_field_id' => $schema->string()
                ->description('Date/datetime field driving the range filter and time axes. Defaults to the first date field, else sys_created_at.'),
            'kpis' => $schema->array()
                ->items($schema->object())
                ->description('1-8 KPI items: {label, aggregation, field_id?, object_slug? (read a different existing object), format?, icon?, filter?, compare?, delta_good?}. Required unless use_suggestion is true.'),
            'charts' => $schema->array()
                ->items($schema->object())
                ->description('1-10 charts: {label, chart_type, aggregation, y_field_id?, group_by_field_id?, x_field_id?, bucket?, series_field_id?, stacked?, filter?, limit?, object_slug? (read a different existing object)}. Vary the chart types. Required unless use_suggestion is true.'),
            'use_suggestion' => $schema->boolean()
                ->description('Compile the deterministic suggested_spec from prepare_dashboard (recomputed server-side — no need to echo it back).'),
            'overrides' => $schema->object()
                ->description('With use_suggestion: parts to replace in the suggestion (title, purpose, date_field_id, kpis, charts, insights, include_hero, include_date_filter). Each key you send replaces that whole part.'),
            'insights' => $schema->array()
                ->items($schema->object())
                ->description('0-4 insight cards: {variant, title, body?, compute?, metric_label?}. metric_label is a short unit under the big figure (e.g. "semanas"). At least one card is required by the dashboard lints. EVERY FIGURE IN A BODY IS CHECKED AGAINST THE ROWS: state only numbers the data contains or that follow from it (a total, a share, an average, the weighted rate of a derived percentage). An invented figure is rejected and named back to you — do not write one from memory, and never pair a count with a percentage unless the count actually divides to it.'),
            'include_hero' => $schema->boolean()
                ->description('Open with a compact left-aligned brand hero (default true).'),
            'include_date_filter' => $schema->boolean()
                ->description('Add the Hoy/7d/30d/90d/Año/Todo range filter wired into every block (default true).'),
            'as_new_page' => $schema->boolean()
                ->description('Force a SECOND dashboard page. By default, building a dashboard whose data an existing overview already covers rewrites that page instead of adding a duplicate.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $slug = trim((string) ($args['object_slug'] ?? ''));
        if ($slug === '') {
            return $this->fail('`object_slug` is required.');
        }

        $base = $this->proposeTool->currentManifest();
        if (! is_array($base)) {
            return $this->fail('No active manifest exists for this app yet.');
        }

        $object = collect($base['objects'] ?? [])->firstWhere('slug', $slug);
        if ($object === null) {
            return $this->fail("No object with slug '{$slug}' exists. Create it first (add_connected_object for an MCP source, or add_object).");
        }

        $lang = AppScaffolder::langForLocale($base['settings']['default_locale'] ?? null);

        // FAST PATH: recompute the deterministic suggestion (same derivation
        // prepare_dashboard showed) and let tiny overrides replace parts — the
        // model no longer has to GENERATE the whole spec, which is the stretch
        // that killed slow-model turns.
        if (($args['use_suggestion'] ?? false) === true) {
            $spec = (new DashboardSpecSuggester)->suggest($object, $lang);
            $overrides = is_array($args['overrides'] ?? null) ? $args['overrides'] : [];
            foreach (['title', 'purpose', 'date_field_id', 'kpis', 'charts', 'insights', 'include_hero', 'include_date_filter'] as $key) {
                if (array_key_exists($key, $overrides)) {
                    $spec[$key] = $overrides[$key];
                }
            }
            $args = $spec + ['object_slug' => $slug] + $args;
        }

        if (empty($args['kpis']) || empty($args['charts'])) {
            return $this->fail('Pass `kpis` and `charts`, or set `use_suggestion: true` to compile the suggested spec.');
        }

        // An insight body is the one place the model writes figures from memory,
        // and memory invents. The Yuhu board shipped "solo 92 de 265 pedidos
        // entregados a tiempo (50.2%)" — 265 real, 50.2% real, 92 invented, and
        // 92/265 is not 50.2% anyway. Nothing checked it, so it went to the
        // director next to a chart that WAS real.
        $unknown = $this->inventedNumbers($args['insights'] ?? [], $object, $base);
        if ($unknown !== []) {
            return json_encode([
                'ok' => false,
                'errors' => array_map(fn (array $issue): array => [
                    'path' => "/insights/{$issue['index']}",
                    'message' => "This insight states {$issue['numbers']}, which the data does not contain. Every figure in an insight must come from the rows you sampled or be computable from them (a total, a share, an average, the weighted rate). Quote a number you can point at, or write the sentence without one — a claim you cannot source is worse than no card.",
                    'code' => 'unverifiable_number',
                ], $unknown),
                'message' => 'Fix the insight bodies and call add_dashboard_page again.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $takenSlugs = array_values(array_filter(array_map(fn ($p) => $p['slug'] ?? null, $base['pages'] ?? [])));
        $palette = ColorPalette::fromAccent((string) ($base['settings']['accent'] ?? OrganizationBrand::DEFAULT_ACCENT));
        $extraObjects = collect($base['objects'] ?? [])
            ->filter(fn ($o) => is_array($o) && ($o['slug'] ?? null) !== $slug)
            ->values()->all();

        $built = $this->scaffolder->buildDashboardFromSpec($args, $object, $takenSlugs, $palette, $lang, $extraObjects);
        if (($built['ok'] ?? false) !== true) {
            return json_encode(['ok' => false, 'errors' => $built['errors'] ?? []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        // The compiler lints its own layout — quality is enforced here, not
        // hoped for. Issues are returned as errors naming what to change in the
        // SPEC (more chart variety, an insight card, …).
        $lint = PlanDashboardTool::lint($built['purpose'], $built['plan_rows']);
        if (! $lint['ok']) {
            return json_encode([
                'ok' => false,
                'errors' => array_map(fn (string $issue): array => ['path' => '/', 'message' => $issue, 'code' => 'dashboard_lint'], $lint['issues']),
                'message' => 'Adjust the spec (content, not layout — the server lays it out) and call add_dashboard_page again.',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $page = $built['page'];

        // Asking for "the dashboard" when one already exists means REPLACE it,
        // not add a second — this tool used to append unconditionally and let
        // uniqueSlug name the collision, which is exactly how an app asked for
        // one dashboard shipped with `/dashboard` and `/dashboard_2` in four
        // separate builds. The platform created the defect; R14 then reported
        // it; a review turn deleted it. So the generator now consults the rule
        // it will be judged by.
        //
        // Nested EITHER WAY counts as the same dashboard: R14 only looks at a
        // later page against earlier ones, so a richer second dashboard that
        // swallows the scaffolded one slips past it and leaves the redundant
        // page behind. The tool knows the intent and can see both directions.
        $existing = $this->existingOverviewToReplace($base, $page, ($args['as_new_page'] ?? false) === true);

        if ($existing !== null) {
            // Keep the page's identity — its id, slug and path — so every link
            // already pointing at the dashboard still arrives somewhere.
            $page['id'] = $existing['page']['id'] ?? $page['id'];
            $page['slug'] = $existing['page']['slug'] ?? $page['slug'];
            $page['path'] = $existing['page']['path'] ?? $page['path'];
        }

        $result = $this->proposeTool->recordProposal(
            $existing === null
                ? [['op' => 'add', 'path' => '/pages/-', 'value' => $page]]
                : [['op' => 'replace', 'path' => '/pages/'.$existing['index'], 'value' => $page]],
            $existing === null
                ? "Agregué el dashboard «{$page['name']}»"
                : "Rehice el dashboard «{$page['name']}»",
        );

        if (($result['ok'] ?? false) === true) {
            $result['page'] = ['slug' => $page['slug'], 'path' => $page['path']];
            $result['hints'] = $lint['hints'];
            $result['replaced'] = $existing !== null;
            $result['message'] = $existing === null
                ? "Dashboard «{$page['name']}» compiled and added at {$page['path']} — KPI band, balanced chart rows, insights and the date-range filter included."
                : "Dashboard «{$page['name']}» compiled and written OVER the existing overview at {$page['path']} (it covered the same data). Pass as_new_page:true if you meant a second, separate dashboard.";
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The existing overview page this dashboard should be written over, if any.
     *
     * Two overviews are the same dashboard when one's objects are nested inside
     * the other's — in either direction. Genuinely different boards («Ventas»
     * beside «Operaciones») each bring data the other lacks and are left alone.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $page
     * @return array{index: int, page: array<string, mixed>}|null
     */
    private function existingOverviewToReplace(array $manifest, array $page, bool $forceNew): ?array
    {
        if ($forceNew) {
            return null;
        }

        $validator = app(ManifestValidator::class);
        $fresh = $validator->overviewPages(['pages' => [$page]]);
        $objects = $fresh[0]['objects'] ?? [];
        if ($objects === []) {
            return null;
        }

        foreach ($validator->overviewPages($manifest) as $index => $overview) {
            $nested = array_diff_key($objects, $overview['objects']) === []
                || array_diff_key($overview['objects'], $objects) === [];

            if ($nested) {
                return ['index' => $index, 'page' => $overview['page']];
            }
        }

        return null;
    }

    /**
     * The insights whose figures the data cannot back.
     *
     * Ground truth is derived, not literal: a real insight says "80% of the backlog
     * sits in 4 reasons" and no cell holds the 80. GroundTruth builds the lattice of
     * numbers the rows support — totals, shares, averages, the weighted rate — and a
     * figure outside it was invented.
     *
     * If the rows cannot be read (a live source that is down, a native object with
     * no records), nothing is claimed and nothing is blocked. This guard exists to
     * catch a lie, not to make an outage look like one.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     * @return list<array{index: int, numbers: string}>
     */
    private function inventedNumbers(mixed $insights, array $object, array $manifest): array
    {
        if (! is_array($insights) || $insights === []) {
            return [];
        }

        try {
            $rows = app(ObjectRowSource::class)->sample($this->appModel, $object, $manifest, $this->actor, 500);
        } catch (\Throwable) {
            return [];
        }
        if ($rows === []) {
            return [];
        }

        $truth = app(GroundTruth::class)->forObject($object, $rows);

        $out = [];
        foreach ($insights as $i => $insight) {
            if (! is_array($insight)) {
                continue;
            }
            $prose = trim(((string) ($insight['title'] ?? '')).' '.((string) ($insight['body'] ?? '')));
            if ($prose === '' || FactGuard::onlyKnownNumbers($prose, $truth)) {
                continue;
            }

            $invented = array_values(array_diff(
                FactGuard::numbersIn($prose),
                FactGuard::numbersIn($truth),
            ));
            $out[] = ['index' => (int) $i, 'numbers' => implode(', ', $invented)];
        }

        return $out;
    }

    private function fail(string $message): string
    {
        return json_encode([
            'ok' => false,
            'errors' => [['path' => '/', 'message' => $message, 'code' => 'bad_input']],
        ], JSON_THROW_ON_ERROR);
    }
}
