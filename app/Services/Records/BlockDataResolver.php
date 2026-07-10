<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Apps\AppAccessContext;
use App\Services\Connected\ConnectedIntegrationResolver;
use App\Services\Connected\ConnectedObjectReader;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Walks a manifest page's block tree and pre-resolves the server-side data
 * each block needs (table → records, stat → aggregation), so the client can
 * hydrate the runtime in one round-trip. Shared by the public runtime
 * controller and the Builder preview pane.
 *
 * Per-block resolution is wrapped in try/catch: a single broken block (e.g.
 * one that references a field_id removed in a later edit) must NOT take down
 * the whole page. We surface the error via blockData[id].error so the renderer
 * can paint a placeholder.
 */
class BlockDataResolver
{
    public function __construct(
        private RecordQueryService $records,
        private ExpressionResolver $expressions,
        private ConnectedObjectReader $connected,
        private ConnectedIntegrationResolver $integrations,
        private InMemoryAggregator $aggregator,
        private InMemoryRowFilter $rowFilter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed> block_id → resolved data
     */
    public function resolve(App $app, array $blocks, array $manifest, array $context = []): array
    {
        // Remember the top-level page blocks across recursion: a filter_bar
        // resolves its date-range meta by scanning the whole page for the
        // date-driven data source it governs.
        $context['__page_blocks'] ??= $blocks;

        // The page's date_range control (if any) as a range_start() expression,
        // threaded so CONNECTED reads can widen their fetch window even for
        // blocks with no date filter of their own — their object has no date
        // field, so without this the KPI/chart stays frozen on the source's
        // authoring-time window while the rest of the page follows the picker.
        if (! array_key_exists('__page_range_start_expr', $context)) {
            $context['__page_range_start_expr'] = $this->pageRangeStartExpression($context['__page_blocks']);
        }

        $data = [];

        foreach ($blocks as $block) {
            // Containers and other layout-only nodes recurse so their nested
            // blocks still get resolved even if a sibling broke.
            if ($block['type'] === 'container' || $block['type'] === 'modal') {
                $data += $this->resolve($app, $block['blocks'] ?? [], $manifest, $context);

                continue;
            }

            if ($block['type'] === 'tabs') {
                foreach ($block['tabs'] ?? [] as $tab) {
                    $data += $this->resolve($app, $tab['blocks'] ?? [], $manifest, $context);
                }

                continue;
            }

            if ($block['type'] === 'accordion') {
                foreach ($block['sections'] ?? [] as $section) {
                    $data += $this->resolve($app, $section['blocks'] ?? [], $manifest, $context);
                }

                continue;
            }

            if ($block['type'] === 'split_view') {
                $data += $this->resolve($app, $block['left_blocks'] ?? [], $manifest, $context);
                $data += $this->resolve($app, $block['right_blocks'] ?? [], $manifest, $context);

                continue;
            }

            try {
                $resolved = $this->resolveDataBlock($app, $block, $manifest, $context);
                if ($resolved !== null) {
                    $data[$block['id']] = $resolved;
                }
            } catch (Throwable $e) {
                Log::warning('Block data resolution failed', [
                    'app_id' => $app->id,
                    'block_id' => $block['id'] ?? null,
                    'block_type' => $block['type'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $data[$block['id']] = ['error' => $e->getMessage()];
            }
        }

        return $data;
    }

    /**
     * Resolve the server-side payload for a single data-bound block. Returns
     * null when the block type does not need server data (the renderer is
     * fully client-side for it, e.g. text/heading/markdown).
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function resolveDataBlock(App $app, array $block, array $manifest, array $context): ?array
    {
        if ($block['type'] === 'table') {
            return ['rows' => $this->queryRows($app, $block['data_source'], $manifest, $context)];
        }

        if ($block['type'] === 'stat' || $block['type'] === 'gauge' || $block['type'] === 'progress') {
            return $this->kpiPayload($app, $block, $manifest, $context);
        }

        // A hero can carry ONE live headline figure (its `stat`), resolved like
        // a KPI so the banner shows a real current number.
        if ($block['type'] === 'hero' && is_array($block['stat'] ?? null)) {
            try {
                return ['stat' => $this->kpiPayload($app, $block['stat'], $manifest, $context)];
            } catch (Throwable $e) {
                return ['stat' => ['error' => $e->getMessage()]];
            }
        }

        // A computed insight: aggregate a live figure (and optional comparison)
        // so the card states a real, current number instead of hand-written
        // prose. Routes through aggregateBlock, so it works over connected
        // objects too. Without `compute`, an insight is static (no server data).
        if ($block['type'] === 'insight' && isset($block['compute'])) {
            return $this->kpiPayload($app, $block['compute'], $manifest, $context);
        }

        if (in_array($block['type'], ['chart', 'kanban', 'calendar', 'sparkline', 'heatmap', 'timeline', 'gantt', 'map', 'card_grid', 'word_cloud', 'data_grid'], true)) {
            return ['rows' => $this->queryRows($app, $block['data_source'], $manifest, $context)];
        }

        if ($block['type'] === 'metric_grid') {
            $items = [];
            foreach ($block['items'] ?? [] as $item) {
                try {
                    $items[$item['id']] = $this->kpiPayload($app, $item, $manifest, $context);
                } catch (Throwable $e) {
                    $items[$item['id']] = ['error' => $e->getMessage()];
                }
            }

            return ['items' => $items];
        }

        if ($block['type'] === 'filter_bar') {
            return $this->filterBarMeta($app, $block, $manifest, $context);
        }

        if ($block['type'] === 'form' || $block['type'] === 'multi_step_form') {
            return $this->resolveFormBlock($block, $manifest, $context);
        }

        if ($block['type'] === 'record_detail') {
            $recordId = $this->expressions->resolve($block['record_id_expression'] ?? '', $context);
            if (! is_string($recordId) || $recordId === '') {
                return ['record' => null];
            }
            $record = $this->records->find($app, $block['object_id'], $recordId, $manifest, $context);

            return ['record' => $record === null
                ? null
                : $this->mapRows([$record], $this->hiddenSlugsFor($context, $block['object_id']))[0]];
        }

        if ($block['type'] === 'related_list') {
            $parentId = $this->expressions->resolve($block['parent_id_expression'] ?? '', $context);
            if (! is_string($parentId) || $parentId === '') {
                return ['rows' => []];
            }

            // The children are the records whose relation field points at the parent.
            $dataSource = [
                'object_id' => $block['object_id'],
                'filter' => ['op' => 'eq', 'field_id' => $block['via_relation_field_id'], 'value' => $parentId],
            ];

            return ['rows' => $this->queryRows($app, $dataSource, $manifest, $context)];
        }

        if ($block['type'] === 'funnel') {
            $stages = [];
            foreach ($block['stages'] ?? [] as $stage) {
                try {
                    $stages[$stage['id']] = [
                        'value' => $this->aggregateBlock(
                            $app,
                            $stage['query'],
                            $stage['aggregation'],
                            $stage['field_id'] ?? null,
                            $manifest,
                            $context,
                        ),
                    ];
                } catch (Throwable $e) {
                    $stages[$stage['id']] = ['error' => $e->getMessage()];
                }
            }

            return ['stages' => $stages];
        }

        return null;
    }

    /**
     * Pre-resolve a form block's per-field expressions against the render
     * context (current_user, params) so the client gets concrete values, not
     * expression strings. `default_expression` becomes the field's initial
     * value; `readonly_expression` becomes a boolean that disables the field.
     * Reactive conditions (visible_if / required_if) are evaluated client-side
     * against live form input and are intentionally NOT touched here.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array{form: array{defaults: array<string, mixed>, readonly: array<string, bool>}}|null
     */
    private function resolveFormBlock(array $block, array $manifest, array $context): ?array
    {
        $object = $this->findObject($manifest, $block['object_id'] ?? null);
        if ($object === null) {
            return null;
        }

        $defaults = [];
        $readonly = [];

        foreach ($this->formFields($block) as $formField) {
            $slug = $this->fieldSlug($object, $formField['field_id'] ?? null);
            if ($slug === null) {
                continue;
            }

            if (isset($formField['default_expression'])) {
                $defaults[$slug] = $this->expressions->resolve($formField['default_expression'], $context);
            }
            if (isset($formField['readonly_expression'])) {
                $readonly[$slug] = (bool) $this->expressions->resolve($formField['readonly_expression'], $context);
            }
        }

        if ($defaults === [] && $readonly === []) {
            return null;
        }

        return ['form' => ['defaults' => $defaults, 'readonly' => $readonly]];
    }

    /**
     * Flatten a form block's field configs — multi_step_form nests them under
     * steps[], a plain form lists them directly.
     *
     * @param  array<string, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function formFields(array $block): array
    {
        if ($block['type'] === 'multi_step_form') {
            $fields = [];
            foreach ($block['steps'] ?? [] as $step) {
                foreach ($step['fields'] ?? [] as $field) {
                    $fields[] = $field;
                }
            }

            return $fields;
        }

        return $block['fields'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findObject(array $manifest, ?string $objectId): ?array
    {
        if ($objectId === null) {
            return null;
        }
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) === $objectId) {
                return $object;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function fieldSlug(array $object, ?string $fieldId): ?string
    {
        if ($fieldId === null) {
            return null;
        }
        foreach ($object['fields'] ?? [] as $field) {
            if (($field['id'] ?? null) === $fieldId) {
                return $field['slug'] ?? null;
            }
        }

        return null;
    }

    /**
     * Source-agnostic row fetch for a data-source query — the same routing the
     * renderer uses, exposed for callers that need rows directly (e.g. the
     * runtime agent's read tools). Returns the unified {id, data} shape for both
     * internal records and connected objects.
     *
     * @param  array<string, mixed>  $dataSource
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return list<array{id: mixed, data: array<string, mixed>}>
     */
    public function queryObject(App $app, array $dataSource, array $manifest, array $context = []): array
    {
        return $this->queryRows($app, $dataSource, $manifest, $context);
    }

    /**
     * Total count of an object's rows matching a data-source filter, ignoring
     * limit/offset — for paging metadata. Returns null for a connected object,
     * which has no internal store to count cheaply (the caller should page off
     * the row count instead).
     *
     * @param  array<string, mixed>  $dataSource
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    public function countObject(App $app, array $dataSource, array $manifest, array $context = []): ?int
    {
        $object = $this->findObject($manifest, $dataSource['object_id'] ?? null);

        if ($object !== null && (($object['source']['type'] ?? 'internal') === 'connected')) {
            return null;
        }

        return $this->records->count($app, $dataSource, $manifest, $context);
    }

    /**
     * Resolve a KPI spec (a stat block, a metric_grid item, or an insight's
     * `compute`) into its payload. A `ratio_denominator` makes the value a ratio
     * (this spec's aggregate ÷ the denominator's, guarded against /0) — no trend
     * chip. Otherwise it is the aggregate, plus an optional `compare` value for
     * the trend chip. All aggregates route through aggregateBlock, so KPIs work
     * over internal and connected objects alike.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function kpiPayload(App $app, array $spec, array $manifest, array $context): array
    {
        if (isset($spec['ratio_denominator'])) {
            $numerator = $this->aggregateBlock($app, $spec['query'], $spec['aggregation'], $spec['field_id'] ?? null, $manifest, $context);
            $den = $spec['ratio_denominator'];
            $denominator = $this->aggregateBlock($app, $den['query'], $den['aggregation'], $den['field_id'] ?? null, $manifest, $context);

            return $this->withSpark($app, $spec, ['value' => $denominator != 0 ? $numerator / $denominator : 0], $manifest, $context);
        }

        $payload = ['value' => $this->aggregateBlock($app, $spec['query'], $spec['aggregation'], $spec['field_id'] ?? null, $manifest, $context)];

        if (isset($spec['compare'])) {
            $payload['compare_value'] = $this->aggregateBlock($app, $spec['compare'], $spec['aggregation'], $spec['field_id'] ?? null, $manifest, $context);
        } elseif (($spec['compare_window'] ?? null) === 'previous') {
            // Dateless connected source: no date field to bracket a compare
            // query with, so the SAME query re-reads the tool one window back
            // (__window: previous, reader-side). The chip is optional — a
            // failed previous read never sinks the KPI itself.
            try {
                $previous = $this->aggregateBlock(
                    $app, $spec['query'], $spec['aggregation'], $spec['field_id'] ?? null, $manifest,
                    ['__window' => 'previous'] + $context,
                );
                // An aggregate of 0 over a windowless-looking past is
                // ambiguous: "genuinely zero last period" earns the chip,
                // "the data simply doesn't reach that far back" must NOT
                // read as «nuevo». Only rows in the previous window (the
                // re-read is memoized, so this count is free) settle it.
                if ((float) $previous !== 0.0 || (float) $this->aggregateBlock(
                    $app, $spec['query'], 'count', null, $manifest,
                    ['__window' => 'previous'] + $context,
                ) > 0.0) {
                    $payload['compare_value'] = $previous;
                }
            } catch (Throwable) {
                // Chip omitted; the value already resolved.
            }
        }

        return $this->withSpark($app, $spec, $payload, $manifest, $context);
    }

    /**
     * Attach the rows for a KPI's optional inline sparkline. The client buckets
     * and draws them (same as the sparkline block), so we just deliver the rows
     * of the spark's data-source. A broken spark query must not sink the KPI, so
     * it fails soft (no spark_rows) rather than throwing.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withSpark(App $app, array $spec, array $payload, array $manifest, array $context): array
    {
        if (! isset($spec['spark']['data_source'])) {
            return $payload;
        }

        try {
            $payload['spark_rows'] = $this->queryRows($app, $spec['spark']['data_source'], $manifest, $context);
        } catch (Throwable $e) {
            Log::warning('KPI sparkline resolution failed', [
                'app_id' => $app->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $payload;
    }

    /**
     * Source-agnostic scalar aggregation for a KPI block (stat/gauge/progress/
     * metric_grid item/funnel stage). Internal objects fold in SQL via
     * RecordQueryService; connected objects have no SQL store, so their mapped
     * passthrough rows are read live and folded in-memory by the shared
     * InMemoryAggregator — the same routing queryRows() does for row blocks, so a
     * dashboard KPI works against an integration, not just internal records.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    private function aggregateBlock(App $app, array $query, string $aggregation, ?string $fieldId, array $manifest, array $context): int|float
    {
        $object = $this->findObject($manifest, $query['object_id'] ?? null);

        if ($object !== null && (($object['source']['type'] ?? 'internal') === 'connected')) {
            $rows = $this->connectedRows($app, $object, $query, $context);
            $slug = $fieldId !== null ? $this->fieldSlug($object, $fieldId) : null;

            return $this->aggregator->aggregate($rows, $aggregation, $slug);
        }

        return $this->records->aggregate($app, $query, $aggregation, $fieldId, $manifest, $context);
    }

    /**
     * Server meta for a filter_bar with a date_range control: the ACTUAL span
     * of data the dashboard is showing under the active preset — row count and
     * min/max of the governing date field. This is what makes the window
     * honest: an external source that caps its list (e.g. "latest 100") or demo
     * data clustered on one day is visible at a glance instead of looking like
     * a broken filter. Best-effort: any failure resolves to null (no meta), the
     * bar itself never errors.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function filterBarMeta(App $app, array $block, array $manifest, array $context): ?array
    {
        $control = collect($block['controls'] ?? [])->first(fn ($c) => ($c['type'] ?? null) === 'date_range');
        if (! is_array($control)) {
            return null;
        }

        try {
            // The data source this bar governs: the first block on the page
            // whose filter uses range_start() on a date field.
            $target = $this->findDateGovernedSource($context['__page_blocks'] ?? []);
            if ($target === null) {
                return null;
            }
            [$objectId, $condition] = $target;

            $object = $this->findObject($manifest, $objectId);
            $slug = $object !== null ? $this->fieldSlug($object, (string) $condition['field_id']) : null;
            if ($object === null || $slug === null) {
                return null;
            }

            // Only the date condition — the span reflects the page's window,
            // not any one block's extra filters. The connected read is memoised,
            // so this adds no extra external call.
            $rows = $this->queryRows($app, ['object_id' => $objectId, 'filter' => $condition], $manifest, $context);

            $timestamps = [];
            foreach ($rows as $row) {
                $ts = InMemoryRowFilter::timestamp($row['data'][$slug] ?? null);
                if ($ts !== null) {
                    $timestamps[] = $ts;
                }
            }

            return ['date_range' => [
                'param' => $control['param'] ?? 'range',
                'count' => count($rows),
                'min' => $timestamps === [] ? null : date('Y-m-d', min($timestamps)),
                'max' => $timestamps === [] ? null : date('Y-m-d', max($timestamps)),
            ]];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The page's governing date_range control rendered as the range_start()
     * expression its governed blocks use — "{{range_start(default(params.range,
     * '90d'))}}" — or null when the page has no date_range filter bar. Param
     * and default are sanitized before interpolation so a malformed manifest
     * can't inject expression syntax.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function pageRangeStartExpression(array $blocks): ?string
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'filter_bar') {
                $control = collect($block['controls'] ?? [])->first(fn ($c) => is_array($c) && ($c['type'] ?? null) === 'date_range');
                if (is_array($control)) {
                    $param = trim((string) ($control['param'] ?? 'range'));
                    if (preg_match('/^[a-z0-9_]+$/i', $param) !== 1) {
                        $param = 'range';
                    }
                    $default = trim((string) ($control['default'] ?? ''));

                    return $default !== '' && preg_match('/^[a-z0-9_]+$/i', $default) === 1
                        ? "{{range_start(default(params.{$param}, '{$default}'))}}"
                        : "{{range_start(params.{$param})}}";
                }
            }

            $nested = match ($block['type'] ?? null) {
                'container', 'modal' => $block['blocks'] ?? [],
                'tabs' => collect($block['tabs'] ?? [])->flatMap(fn ($t) => $t['blocks'] ?? [])->all(),
                'accordion' => collect($block['sections'] ?? [])->flatMap(fn ($s) => $s['blocks'] ?? [])->all(),
                'split_view' => array_merge($block['left_blocks'] ?? [], $block['right_blocks'] ?? []),
                default => [],
            };
            if ($nested !== []) {
                $found = $this->pageRangeStartExpression($nested);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Find the first data-bearing block (recursing through layout nodes and
     * metric_grid items) whose filter contains a range_start() condition —
     * the source a date_range filter bar governs.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array{0: string, 1: array<string, mixed>}|null [object_id, date condition]
     */
    private function findDateGovernedSource(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $nested = match ($block['type'] ?? null) {
                'container', 'modal' => $block['blocks'] ?? [],
                'tabs' => collect($block['tabs'] ?? [])->flatMap(fn ($t) => $t['blocks'] ?? [])->all(),
                'accordion' => collect($block['sections'] ?? [])->flatMap(fn ($s) => $s['blocks'] ?? [])->all(),
                'split_view' => array_merge($block['left_blocks'] ?? [], $block['right_blocks'] ?? []),
                default => [],
            };
            if ($nested !== []) {
                $found = $this->findDateGovernedSource($nested);
                if ($found !== null) {
                    return $found;
                }
            }

            $candidates = [];
            if (is_array($block['data_source'] ?? null)) {
                $candidates[] = $block['data_source'];
            }
            if (is_array($block['query'] ?? null)) {
                $candidates[] = $block['query'];
            }
            foreach ($block['items'] ?? [] as $item) {
                if (is_array($item['query'] ?? null)) {
                    $candidates[] = $item['query'];
                }
            }

            foreach ($candidates as $source) {
                $objectId = $source['object_id'] ?? null;
                $condition = is_array($source['filter'] ?? null) ? $this->findRangeCondition($source['filter']) : null;
                if (is_string($objectId) && $condition !== null) {
                    return [$objectId, $condition];
                }
            }
        }

        return null;
    }

    /**
     * Locate the range_start() condition inside a filter tree.
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>|null
     */
    private function findRangeCondition(array $filter): ?array
    {
        if (str_contains((string) ($filter['value_expression'] ?? ''), 'range_start')
            && is_string($filter['field_id'] ?? null)) {
            return $filter;
        }

        foreach ($filter['conditions'] ?? [] as $cond) {
            if (is_array($cond)) {
                $found = $this->findRangeCondition($cond);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        if (is_array($filter['condition'] ?? null)) {
            return $this->findRangeCondition($filter['condition']);
        }

        return null;
    }

    /**
     * Fetch a data-source's rows, routing to the external system for a connected
     * object (source.type === 'connected') or the internal records store
     * otherwise. Both paths return the same {id, data} row shape, so the renderer
     * is source-agnostic. A connected-read failure throws, surfacing as the
     * block's error state (caught by resolve()).
     *
     * @param  array<string, mixed>  $dataSource
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return list<array{id: mixed, data: array<string, mixed>}>
     */
    private function queryRows(App $app, array $dataSource, array $manifest, array $context): array
    {
        $object = $this->findObject($manifest, $dataSource['object_id'] ?? null);

        if ($object !== null && (($object['source']['type'] ?? 'internal') === 'connected')) {
            return $this->connectedRows($app, $object, $dataSource, $context);
        }

        return $this->mapRows(
            $this->records->query($app, $dataSource, $manifest, $context),
            $this->hiddenSlugsFor($context, $dataSource['object_id'] ?? null),
        );
    }

    /**
     * Field slugs the current user's role may not read on an object, pulled from
     * the AppAccessContext in $context['__access']. Empty when no access context
     * is threaded or the object has no field restrictions.
     *
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function hiddenSlugsFor(array $context, ?string $objectId): array
    {
        $access = $context['__access'] ?? null;
        if ($objectId === null || ! $access instanceof AppAccessContext) {
            return [];
        }

        return $access->hiddenFieldSlugs($objectId);
    }

    /**
     * Read a connected object's rows live from its external system (passthrough)
     * and normalize them to the {id, data} shape, using the external id as the
     * row identity. The block's data-source query (filter/sort/pagination) is
     * pushed down to the external API where the source declares the mapping.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $dataSource
     * @return list<array{id: mixed, data: array<string, mixed>}>
     */
    private function connectedRows(App $app, array $object, array $dataSource, array $context = []): array
    {
        $integration = $this->integrations->resolve($app, $object['source']['integration_id'] ?? null);
        if ($integration === null) {
            throw new RuntimeException('This connected object needs an authorized connection.');
        }

        // The acting viewer, threaded so a per-user OAuth MCP source reads with
        // their token (null → the integration's own credentials for static /
        // service auth).
        $actor = $context['__actor'] ?? null;

        // The render context (params) is threaded into the reader so a live
        // source with a start-date argument can have its FETCH window widened to
        // the picked date-range preset — the in-memory filter below can only trim
        // what the source already returned, never widen it.
        $result = $this->connected->list($object, $integration, $dataSource, $actor instanceof User ? $actor : null, $context);
        if (! ($result['ok'] ?? false)) {
            throw new RuntimeException($result['error'] ?? 'Could not read from the connected system.');
        }

        $rows = array_map(function (array $row): array {
            $id = $row['_external_id'] ?? null;
            unset($row['_external_id']);

            return ['id' => $id, 'data' => $row];
        }, $result['rows']);

        // The external read can't run our filter grammar (REST pushes down only
        // mapped equality params; MCP nothing), so the data-source query is
        // applied here in memory — this is what makes the dashboard date-range
        // presets actually re-scope live connected data.
        return $this->rowFilter->apply($rows, $dataSource, $object, $context);
    }

    /**
     * Project a record collection to the shape the frontend expects, merging
     * system fields (id, sys_created_at, sys_updated_at) into `data` so
     * visualisation blocks can reference them by id like any other field.
     *
     * @param  iterable<int, Record>  $records
     * @param  list<string>  $hiddenSlugs  field slugs to strip from every row (field_restrictions.hidden)
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    private function mapRows(iterable $records, array $hiddenSlugs = []): array
    {
        $out = [];
        foreach ($records as $r) {
            $data = $r->data ?? [];
            foreach ($hiddenSlugs as $slug) {
                unset($data[$slug]);
            }
            $data['id'] = $r->id;
            $data['sys_created_at'] = optional($r->created_at)->toIso8601String();
            $data['sys_updated_at'] = optional($r->updated_at)->toIso8601String();
            $row = ['id' => $r->id, 'data' => $data];
            // Inline-expanded belongs_to relations (RecordQueryService::query with
            // `expand`); already access- and field-hiding-safe at the engine level.
            if (! empty($r->expanded)) {
                $row['expanded'] = $r->expanded;
            }
            $out[] = $row;
        }

        return $out;
    }
}
