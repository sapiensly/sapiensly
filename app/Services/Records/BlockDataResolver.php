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
    /**
     * The row ceiling a block gets when its data source names none. Mirrors
     * RecordQueryService's own default — this class only needs it to tell a
     * full page from a short one.
     */
    private const DEFAULT_ROW_LIMIT = 50;

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
        $isRootCall = ! array_key_exists('__page_blocks', $context);
        $context['__page_blocks'] ??= $blocks;

        // The page's date_range control (if any) as a range_start() expression,
        // threaded so CONNECTED reads can widen their fetch window even for
        // blocks with no date filter of their own — their object has no date
        // field, so without this the KPI/chart stays frozen on the source's
        // authoring-time window while the rest of the page follows the picker.
        if (! array_key_exists('__page_range_start_expr', $context)) {
            $context['__page_range_start_expr'] = $this->pageRangeStartExpression($context['__page_blocks']);
        }

        // Warm every distinct connected read CONCURRENTLY before the block
        // loop touches the first one — a complex dashboard used to pay its
        // 7-11 MCP round-trips in sequence.
        if ($isRootCall) {
            $this->prefetchConnectedReads($app, $blocks, $manifest, $context);
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
            // Is THIS table the one showing the trash? Addressed by block id
            // rather than a page-wide flag: a dashboard with a list beside four
            // KPI cards must not have the cards start counting deleted rows
            // because somebody opened the trash on the list.
            $trashed = $this->trashRequestedFor($block, $context);
            $context['__trashed'] = $trashed;

            $source = $this->applyTableView($block, $manifest, $context);
            // Whether the server answered this table's own question. Sticky, so
            // a search that happens to return three rows does not hand control
            // back to the browser mid-interaction and lose the other 297.
            $paged = $source !== $block['data_source'];
            $block['data_source'] = $source;
            $rows = $this->queryRows($app, $block['data_source'], $manifest, $context);

            // How many there ARE, not just how many were sent. The table sorts
            // and searches what it was given, and without this it cannot tell
            // the difference between "no such record" and "not in the first
            // page" — so it said the first one. Only asked when the result
            // filled the limit: a short page already knows its own size.
            $limit = (int) ($block['data_source']['limit'] ?? self::DEFAULT_ROW_LIMIT);
            $total = count($rows) < $limit
                ? count($rows)
                : $this->records->count($app, $block['data_source'], $manifest, $context);

            return [
                'rows' => $rows,
                'total' => $total,
                'truncated' => $total > count($rows),
                'paged' => $paged,
                'totals' => $this->moneyTotals($app, $block, $manifest, $context),
                // What this role may do to these rows in bulk. Sent so the bar
                // is never offered to somebody the server will refuse — a
                // control that answers "no" is one that should not have been
                // there, which is the rule the action columns already follow.
                'can' => $this->bulkAbilities($block, $context),
                'trashed' => $trashed,
                // How much is in the trash, so the way in is only drawn when
                // it leads somewhere. Asked only of somebody who could delete:
                // for everyone else the answer is not theirs and the count is
                // a query nobody needed.
                'trash_count' => $this->trashCount($app, $block, $manifest, $context),
            ];
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

        if ($block['type'] === 'chart') {
            return $this->chartPayload($app, $block, $manifest, $context);
        }

        // A pivot is the 2-D breakdown the query layer has always been able to
        // compute and nothing could draw: rows × columns, one aggregate per cell.
        if ($block['type'] === 'pivot') {
            try {
                return ['groups' => $this->groupedBlock(
                    $app,
                    $block['data_source'],
                    (string) ($block['aggregation'] ?? 'count'),
                    $block['y_field_id'] ?? null,
                    (string) $block['group_by_field_id'],
                    $block['bucket'] ?? null,
                    is_numeric($block['data_source']['limit'] ?? null) ? (int) $block['data_source']['limit'] : 400,
                    $manifest,
                    $context,
                    (string) $block['column_field_id'],
                    $block['column_bucket'] ?? null,
                )];
            } catch (Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        }

        if (in_array($block['type'], ['kanban', 'calendar', 'sparkline', 'heatmap', 'timeline', 'gantt', 'map', 'card_grid', 'word_cloud', 'data_grid'], true)) {
            return [
                'rows' => $this->queryRows($app, $block['data_source'], $manifest, $context),
                // What this role may do to these rows. The blocks that let
                // somebody DRAG a record to a new date or a new column need it
                // for the same reason the table's bulk bar does: a control that
                // is always refused should not be drawn.
                'can' => $this->bulkAbilities($block, $context),
            ];
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
            return $this->resolveFormBlock($app, $block, $manifest, $context);
        }

        if ($block['type'] === 'record_detail') {
            $recordId = $this->expressions->resolve($block['record_id_expression'] ?? '', $context);
            if (! is_string($recordId) || $recordId === '') {
                return ['record' => null];
            }
            $record = $this->records->find($app, $block['object_id'], $recordId, $manifest, $context);
            if ($record === null) {
                return ['record' => null];
            }

            // find() does not expand, and a detail page is exactly where
            // "which ticket?" needs an answer a person can read. One read per
            // relation, through the same access-checked finder.
            $object = $this->findObject($manifest, $block['object_id']);
            $plan = $this->relationLabelPlan($object, $manifest);
            $labels = [];
            foreach ($object['fields'] ?? [] as $field) {
                $entry = $plan[$field['id'] ?? ''] ?? null;
                $fk = $entry !== null ? ($record->data[$entry['slug']] ?? null) : null;
                if ($entry === null || ! is_string($fk) || $fk === '') {
                    continue;
                }

                $related = $this->records->find($app, $field['target_object_id'], $fk, $manifest, $context);
                $label = $related?->data[$entry['display']] ?? null;
                if (is_scalar($label) && (string) $label !== '') {
                    $labels[$entry['slug']] = (string) $label;
                }
            }

            $row = $this->mapRows([$record], $this->hiddenSlugsFor($context, $block['object_id']))[0];
            if ($labels !== []) {
                $row['labels'] = $labels;
            }

            return ['record' => $row];
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
    private function resolveFormBlock(App $app, array $block, array $manifest, array $context): ?array
    {
        $object = $this->findObject($manifest, $block['object_id'] ?? null);
        if ($object === null) {
            return null;
        }

        // An EDIT form opens on a record, so its initial values are that
        // record's — resolvable here whenever the id is knowable at render time
        // (a detail page's {{params.id}}). A form whose id only exists once a
        // control hands it over (a modal opened from a row) resolves to nothing
        // and is seeded client-side instead, from the row that opened it.
        //
        // The record wins over `default_expression`: a default is what a NEW
        // record starts as, and overriding a stored value with one would
        // silently rewrite the row the moment someone pressed save.
        $defaults = ($block['mode'] ?? null) === 'edit'
            ? $this->editingRecordData($app, $block, $manifest, $context)
            : [];
        $readonly = [];

        foreach ($this->formFields($block) as $formField) {
            $slug = $this->fieldSlug($object, $formField['field_id'] ?? null);
            if ($slug === null) {
                continue;
            }

            if (isset($formField['default_expression']) && ! array_key_exists($slug, $defaults)) {
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
     * The stored values of the record an edit form is editing, keyed by slug,
     * or [] when the block's record id is not resolvable at render time.
     *
     * Read through the same row-filter-aware finder the rest of the runtime
     * uses, with the role's read-hidden fields stripped — so a form can never
     * seed itself with a value its user is not allowed to see.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function editingRecordData(App $app, array $block, array $manifest, array $context): array
    {
        $recordId = $this->expressions->resolve($block['record_id_expression'] ?? '', $context);
        if (! is_string($recordId) || $recordId === '') {
            return [];
        }

        $record = $this->records->find($app, $block['object_id'], $recordId, $manifest, $context);
        if ($record === null) {
            return [];
        }

        $data = $record->data ?? [];
        foreach ($this->hiddenSlugsFor($context, $block['object_id']) as $slug) {
            unset($data[$slug]);
        }

        return $data;
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
     * @param  array<string, mixed>  $object
     */
    private function fieldIdBySlug(array $object, string $slug): ?string
    {
        foreach ($object['fields'] ?? [] as $field) {
            if (($field['slug'] ?? null) === $slug) {
                return $field['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * The object a combo series reads: its full data_source if given, else a
     * bare `object_id` shortcut (the shape the model naturally reaches for to
     * overlay another object), else the chart's own data_source.
     *
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $blockSource
     * @return array<string, mixed>
     */
    private function seriesSource(array $series, array $blockSource): array
    {
        if (is_array($series['data_source'] ?? null)) {
            return $series['data_source'];
        }
        if (is_string($series['object_id'] ?? null) && $series['object_id'] !== '') {
            return ['object_id' => $series['object_id']];
        }

        return $blockSource;
    }

    /**
     * The X field a combo series groups by. An explicit per-series field wins;
     * otherwise, when the series reads a DIFFERENT object than the chart, map the
     * chart's X into that object BY SLUG (shared-schema sources like venta vs
     * entrega differ only in field ids); else the chart's own X.
     *
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $blockSource
     * @param  array<string, mixed>  $manifest
     */
    private function seriesGroupField(array $series, array $blockSource, string $groupFieldId, ?string $blockGroupSlug, array $manifest): string
    {
        $explicit = $series['group_by_field_id'] ?? $series['x_field_id'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $seriesObjectId = $this->seriesSource($series, $blockSource)['object_id'] ?? null;
        if ($seriesObjectId !== null && $seriesObjectId !== ($blockSource['object_id'] ?? null) && $blockGroupSlug !== null) {
            $mapped = $this->fieldIdBySlug($this->findObject($manifest, $seriesObjectId) ?? [], $blockGroupSlug);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return $groupFieldId;
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

            // A ratio is a 0..1 fraction; the UI multiplies by 100 for format:percentage.
            return $this->withSpark($app, $spec, ['value' => $denominator != 0 ? $numerator / $denominator : 0, 'value_scale' => 'fraction'], $manifest, $context);
        }

        // A plain aggregate is on the field's native scale — a *_pct column already
        // reads 0..100, so format:percentage must NOT multiply it again. `value_scale`
        // tells the UI which so the same "percentage" format is correct for a ratio
        // (0.85 → 85%) and for avg(otd_pct) (94.6 → 94.6%) alike.
        $payload = ['value' => $this->aggregateBlock($app, $spec['query'], $spec['aggregation'], $spec['field_id'] ?? null, $manifest, $context), 'value_scale' => 'unit'];

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
    /**
     * A chart's payload — GROUPS, not rows, wherever the chart is a breakdown.
     *
     * A chart used to be handed raw rows and fold them in JavaScript, so what it
     * drew was only as true as the row window it happened to fetch: a breakdown
     * of five hundred tickets over a twelve-row limit charted twelve tickets and
     * looked confident about it. The grouping now happens where the data lives —
     * in SQL for an internal object, in memory for a connected one — so the chart
     * plots every record that matches, and `limit` caps CATEGORIES (which is what
     * it always meant) instead of silently truncating the evidence.
     *
     * Charts that genuinely need row-level data keep it: a scatter plots one
     * point per record, a box needs every value to find its quartiles, and the
     * multi-series forms (stacked, radar, sankey) still fold client-side.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function chartPayload(App $app, array $block, array $manifest, array $context): array
    {
        $dataSource = $block['data_source'];
        $groupFieldId = $block['group_by_field_id'] ?? $block['x_field_id'] ?? null;

        if (! is_string($groupFieldId) || ! $this->groupsInsteadOfRows($block)) {
            return ['rows' => $this->queryRows($app, $dataSource, $manifest, $context)];
        }

        $bucket = $block['bucket'] ?? null;
        $limit = is_numeric($dataSource['limit'] ?? null) ? (int) $dataSource['limit'] : 100;

        try {
            // A combo overlays several typed measures on one X — each is its own
            // aggregate, and each keeps its own aggregation (volume summed, rate
            // averaged), which is exactly why they can't share one fold. A series
            // may also read its OWN object (its data_source, or a bare object_id
            // shortcut), so a combo can overlay two SEPARATE objects that share an
            // X — the client aligns the series by X VALUE, whichever object each
            // came from.
            if (is_array($block['series'] ?? null) && $block['series'] !== []) {
                // Map the block's X to the series' object BY SLUG when a series
                // crosses objects without naming its own X: venta/entrega share
                // the schema (same slug) but differ in field ids, and forcing the
                // model to re-derive the id per object is the exact friction that
                // made it give up. It provides object_id + field_id; we resolve X.
                $blockGroupSlug = $this->fieldSlug($this->findObject($manifest, $dataSource['object_id'] ?? null) ?? [], $groupFieldId);

                return ['combo' => array_map(
                    fn (array $s): array => ['groups' => $this->groupedBlock(
                        $app,
                        $this->seriesSource($s, $dataSource),
                        (string) ($s['aggregation'] ?? 'count'),
                        $s['field_id'] ?? null,
                        $this->seriesGroupField($s, $dataSource, $groupFieldId, $blockGroupSlug, $manifest),
                        $bucket,
                        $limit,
                        $manifest,
                        $context,
                    )],
                    array_values($block['series']),
                )];
            }

            return ['groups' => $this->groupedBlock(
                $app,
                $dataSource,
                (string) ($block['aggregation'] ?? 'count'),
                $block['y_field_id'] ?? null,
                $groupFieldId,
                $bucket,
                $limit,
                $manifest,
                $context,
                // A second categorical (a stacked bar, a radar's overlaid
                // polygons, a sankey's target column) is a pivot, not a
                // separate query: {group, group2, value}.
                $block['series_field_id'] ?? null,
            )];
        } catch (Throwable $e) {
            // A chart that cannot be aggregated (a derived field in a shape SQL
            // can't fold, a source that won't read) says so, rather than drawing
            // a plausible picture from whatever rows it could get.
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Whether this chart reads as a breakdown the server can aggregate.
     *
     * @param  array<string, mixed>  $block
     */
    private function groupsInsteadOfRows(array $block): bool
    {
        // The only forms that genuinely need the records themselves: a scatter
        // plots one point per row, and a box needs every value in a category to
        // find its quartiles. Everything else is a breakdown.
        return ! in_array($block['chart_type'] ?? '', ['scatter', 'box'], true);
    }

    /**
     * One aggregate per category, over an internal object (SQL) or a connected
     * one (in memory) — the grouped twin of {@see aggregateBlock}.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return list<array{group: mixed, value: int|float}>
     */
    private function groupedBlock(App $app, array $query, string $aggregation, ?string $fieldId, string $groupFieldId, ?string $bucket, int $limit, array $manifest, array $context, ?string $secondGroupFieldId = null, ?string $secondBucket = null): array
    {
        $object = $this->findObject($manifest, $query['object_id'] ?? null);

        if ($object !== null && (($object['source']['type'] ?? 'internal') === 'connected')) {
            $rows = $this->connectedRows($app, $object, $query, $context);

            return $this->aggregator->grouped(
                $rows,
                $aggregation,
                $fieldId !== null ? $this->fieldSlug($object, $fieldId) : null,
                (string) $this->fieldSlug($object, $groupFieldId),
                $bucket,
                secondGroupSlug: $secondGroupFieldId !== null ? $this->fieldSlug($object, $secondGroupFieldId) : null,
                secondBucket: $secondBucket,
            );
        }

        return $this->records->groupedAggregate(
            $app,
            $query,
            $aggregation,
            $fieldId,
            $groupFieldId,
            $bucket,
            $manifest,
            $context,
            $limit,
            $secondGroupFieldId,
            $secondBucket,
        );
    }

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

        // Resolve the relations this object points at, so a cell shows the
        // record's name instead of its id. Batched by the query service (one
        // extra read per relation, no N+1), and only when the object actually
        // has a belongs-to.
        $labelPlan = $this->relationLabelPlan($object, $manifest);
        if ($labelPlan !== []) {
            $dataSource['expand'] = array_values(array_unique([
                ...($dataSource['expand'] ?? []),
                ...array_keys($labelPlan),
            ]));
        }

        return $this->mapRows(
            $this->records->query($app, $dataSource, $manifest, $context),
            $this->hiddenSlugsFor($context, $dataSource['object_id'] ?? null),
            $labelPlan,
        );
    }

    /**
     * The URL key a table's own sort/search/page ride on.
     *
     * Derived identically here and in the browser from the block's id, so the
     * two cannot drift, and shortened because three of these end up in a URL a
     * person may share. Two tables on one page would have to collide on the
     * last six characters of a ULID to clash.
     */
    public static function tableParamKey(string $blockId): string
    {
        return 't'.substr($blockId, -6);
    }

    /**
     * Fold the reader's own view of a table — what they searched for, sorted
     * by, and which page they are on — into the query that feeds it.
     *
     * Present only once they have touched something: until then the block
     * loads its whole ceiling and the browser sorts and searches that, which is
     * instant and needs no round trip. Past the ceiling that stops being
     * possible, so the question goes to the database instead of being answered
     * from a page of it.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function applyTableView(array $block, array $manifest, array $context): array
    {
        $source = $block['data_source'];
        $params = is_array($context['params'] ?? null) ? $context['params'] : [];
        $key = self::tableParamKey((string) ($block['id'] ?? ''));

        $search = trim((string) ($params[$key.'_q'] ?? ''));
        $sort = trim((string) ($params[$key.'_s'] ?? ''));
        $page = max(1, (int) ($params[$key.'_p'] ?? 1));

        if ($search === '' && $sort === '' && $page === 1) {
            return $source;
        }

        if ($search !== '') {
            $source['search'] = $search;
        }

        // The URL carries the field's slug, which is readable and stable; the
        // query layer wants its id.
        if ($sort !== '') {
            [$slug, $direction] = array_pad(explode(':', $sort, 2), 2, 'asc');
            $object = $this->findObject($manifest, $source['object_id'] ?? null);
            $field = collect($object['fields'] ?? [])->firstWhere('slug', $slug);
            if ($field !== null) {
                $source['sort'] = [[
                    'field_id' => $field['id'],
                    'direction' => $direction === 'desc' ? 'desc' : 'asc',
                ]];
            }
        }

        // One page at a time now, rather than a ceiling the browser pages
        // through: the rows past it are exactly what this exists to reach.
        $pageSize = (int) ($block['pagination']['page_size'] ?? 25);
        $source['limit'] = $pageSize;
        $source['offset'] = ($page - 1) * $pageSize;

        return $source;
    }

    /**
     * Whether this role may change or remove the rows of a table's object.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $context
     * @return array{update: bool, delete: bool}
     */
    private function bulkAbilities(array $block, array $context): array
    {
        $access = $context['__access'] ?? null;
        $objectId = $block['data_source']['object_id'] ?? null;

        if (! $access instanceof AppAccessContext || ! is_string($objectId)) {
            return ['update' => false, 'delete' => false];
        }

        return [
            'update' => $access->can($objectId, 'update'),
            'delete' => $access->can($objectId, 'delete'),
        ];
    }

    /**
     * Whether this block was asked to show its trash.
     *
     * Carried in the page's own query string (`?trash=<block id>`), which is
     * what an Inertia partial reload preserves — so the toggle survives paging,
     * sorting and searching without a second transport for one boolean.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $context
     */
    private function trashRequestedFor(array $block, array $context): bool
    {
        if (! $this->onAuthenticatedRuntime()) {
            return false;
        }

        return ($context['params']['trash'] ?? null) === $block['id'];
    }

    /**
     * The trash belongs to the authenticated runtime only.
     *
     * A public portal grants strangers a read, and sometimes a write; it must
     * never hand them a list of what the business deleted. This mirrors the
     * relation options endpoint, which is withheld from portals for the same
     * reason — a portal's grants are about the visitor's own row, not the
     * object's history.
     */
    private function onAuthenticatedRuntime(): bool
    {
        return request()->is('r/*');
    }

    /**
     * How many of this table's records are in the trash.
     *
     * Counted through the same scoped read as the rows themselves, so it
     * honours the environment and the role's row_filter: a count that included
     * rows the reader could never restore would send them looking for
     * something that is not there.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    private function trashCount(App $app, array $block, array $manifest, array $context): int
    {
        if (! $this->onAuthenticatedRuntime() || ! $this->bulkAbilities($block, $context)['delete']) {
            return 0;
        }

        try {
            return $this->records->count(
                $app,
                ['object_id' => $block['data_source']['object_id'] ?? null],
                $manifest,
                ['__trashed' => true] + $context,
            );
        } catch (Throwable) {
            // A count is furniture. It must never be the reason a page 500s.
            return 0;
        }
    }

    /**
     * The sum of every money column in a table, over the WHOLE result.
     *
     * A column of amounts with nothing at the bottom is a column somebody adds
     * up by hand. Computed server-side against the table's own query, so it
     * honours the filters and the access row_filter and stays right across
     * pages — summing the loaded rows would answer a different question than
     * the one the footer appears to be answering, which is worse than no
     * answer.
     *
     * Money ONLY. A `number` column is as likely to hold a year, a mileage or a
     * count of bedrooms, and "Año: 6,073" is not a fact about anything. A
     * rollup counts as money when what it sums is.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     * @return array<string, float>
     */
    private function moneyTotals(App $app, array $block, array $manifest, array $context): array
    {
        $object = $this->findObject($manifest, $block['data_source']['object_id'] ?? null);
        if ($object === null) {
            return [];
        }

        $totals = [];
        foreach ($block['columns'] ?? [] as $column) {
            $fieldId = $column['field_id'] ?? null;
            if (! is_string($fieldId) || isset($totals[$fieldId])) {
                continue;
            }

            $field = collect($object['fields'] ?? [])->firstWhere('id', $fieldId);
            if ($field === null || ! $this->isMoneyField($field, $object, $manifest)) {
                continue;
            }

            try {
                $totals[$fieldId] = (float) $this->records->aggregate(
                    $app,
                    $block['data_source'],
                    'sum',
                    $fieldId,
                    $manifest,
                    $context,
                );
            } catch (Throwable) {
                // A total is a courtesy. Losing it must never cost the table.
            }
        }

        return $totals;
    }

    /**
     * Whether a field holds an amount of money — directly, or by summing one.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $manifest
     */
    private function isMoneyField(array $field, array $object, array $manifest): bool
    {
        $type = $field['type'] ?? null;

        if ($type === 'currency') {
            return true;
        }

        if ($type !== 'rollup' || ($field['aggregator'] ?? null) === 'count') {
            return false;
        }

        // A rollup is money when the column it adds up is. The target lives on
        // another object, reached through the relation the rollup reads over.
        $via = collect($object['fields'] ?? [])->firstWhere('id', $field['via_relation_field_id'] ?? null);
        $target = $this->findObject($manifest, $via['target_object_id'] ?? null);
        $sourceField = collect($target['fields'] ?? [])->firstWhere('id', $field['target_field_id'] ?? null);

        return ($sourceField['type'] ?? null) === 'currency';
    }

    /**
     * Which of an object's relation fields can be shown by name, and which
     * field of the target says that name.
     *
     * Only belongs-to: a has-many holds many records and has no single label to
     * stand in for them. The target's `primary_display_field_id` is the
     * author's answer when they set one; otherwise the first text field is the
     * same guess the scaffolder makes when it picks a card title.
     *
     * @param  array<string, mixed>|null  $object
     * @param  array<string, mixed>  $manifest
     * @return array<string, array{slug: string, display: string}>
     */
    private function relationLabelPlan(?array $object, array $manifest): array
    {
        $plan = [];

        foreach ($object['fields'] ?? [] as $field) {
            if (($field['type'] ?? null) !== 'relation'
                || ($field['cardinality'] ?? null) !== 'many_to_one') {
                continue;
            }

            $target = $this->findObject($manifest, $field['target_object_id'] ?? null);
            if ($target === null) {
                continue;
            }

            // Shared with the relation picker: choosing a vehicle by its plate
            // and then seeing its id in the column would read as two records.
            $display = RecordLabel::displaySlug($target);

            if ($display !== null) {
                $plan[$field['id']] = ['slug' => $field['slug'], 'display' => $display];
            }
        }

        return $plan;
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
    private function mapRows(iterable $records, array $hiddenSlugs = [], array $labelPlan = []): array
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

            // Display text for each relation, keyed by the field's own slug —
            // the id stays in `data` untouched, since filters and actions
            // address the record by it.
            $labels = [];
            $goneLabels = [];
            foreach ($labelPlan as $fieldId => $plan) {
                $related = $r->expanded[$fieldId] ?? null;
                $label = is_array($related) ? ($related['data'][$plan['display']] ?? null) : null;
                if (is_scalar($label) && (string) $label !== '') {
                    $labels[$plan['slug']] = (string) $label;

                    // Which of those names belong to records that are in the
                    // trash. Sent as a flag rather than as words: the cell is
                    // read in the app's language, not the server's.
                    if (($related['trashed'] ?? false) === true) {
                        $goneLabels[$plan['slug']] = true;
                    }
                }
            }
            if ($labels !== []) {
                $row['labels'] = $labels;
            }
            if ($goneLabels !== []) {
                $row['labels_trashed'] = $goneLabels;
            }
            // Inline-expanded belongs_to relations (RecordQueryService::query with
            // `expand`); already access- and field-hiding-safe at the engine level.
            if (! empty($r->expanded)) {
                $row['expanded'] = $r->expanded;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Pool the page's distinct connected reads (one per object, plus the
     * previous-window variant when any KPI compares against it) into the
     * reader's memo. Strictly an accelerator: any failure here is swallowed
     * and the serial path — with its retry ladder — decides for real.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $context
     */
    private function prefetchConnectedReads(App $app, array $blocks, array $manifest, array $context): void
    {
        try {
            $json = json_encode($blocks) ?: '';
            preg_match_all('/"object_id":"([^"]+)"/', $json, $m);
            $ids = array_values(array_unique($m[1] ?? []));
            if ($ids === []) {
                return;
            }
            $objects = collect($manifest['objects'] ?? [])->filter(
                fn ($o): bool => is_array($o)
                    && in_array($o['id'] ?? null, $ids, true)
                    && ($o['source']['type'] ?? null) === 'connected',
            );
            if ($objects->isEmpty()) {
                return;
            }

            $actorRaw = $context['__actor'] ?? auth()->user();
            $actor = $actorRaw instanceof User ? $actorRaw : null;
            $hasPreviousWindow = str_contains($json, '"compare_window":"previous"');

            $reads = [];
            foreach ($objects as $object) {
                $integration = $this->integrations->resolve($app, $object['source']['integration_id'] ?? null);
                if ($integration === null) {
                    continue;
                }
                $reads[] = ['object' => $object, 'integration' => $integration, 'query' => [], 'actor' => $actor, 'context' => $context];
                if ($hasPreviousWindow) {
                    $reads[] = ['object' => $object, 'integration' => $integration, 'query' => [], 'actor' => $actor, 'context' => ['__window' => 'previous'] + $context];
                }
            }
            if ($reads !== []) {
                $this->connected->prefetch($reads);
            }
        } catch (Throwable $e) {
            Log::debug('Connected prefetch skipped', ['error' => $e->getMessage()]);
        }
    }
}
