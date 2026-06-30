<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Profiles the REAL data under one object so the builder can choose
 * visualisations like an analyst instead of guessing. Where inspect_records
 * shows raw sample rows, this classifies every field by its analytic ROLE
 * (measure / temporal / categorical / identifier / relation) and reports the
 * stats that decide which chart fits — cardinality (a 4-value status → donut; a
 * 500-value field → top-N hbar, never a pie), numeric min/max/avg/sum, the date
 * span (enough history for a time series?) and completeness (% nulls). It then
 * suggests concrete KPIs and charts grounded in what the data actually supports.
 *
 * Pair it with list_dashboard_blueprints: the blueprint says WHAT an expert in
 * the sector tracks; this says WHICH of those the data can actually back, and
 * with which block.
 */
class ProfileObjectTool implements Tool
{
    /** Numeric stored types that aggregate in SQL. */
    private const MEASURE_TYPES = ['number', 'currency', 'rating', 'slider'];

    /** Computed types — numeric ones still aggregate (folded in PHP). */
    private const DERIVED_TYPES = ['formula', 'lookup', 'rollup'];

    private const TEMPORAL_TYPES = ['date', 'datetime'];

    private const CATEGORICAL_TYPES = ['single_select', 'multi_select', 'boolean'];

    /** Distinct-value ceiling: at/above this a categorical/text field is "high cardinality". */
    private const CARDINALITY_CAP = 50;

    private const TOP_VALUES = 8;

    /** Min distinct day/month buckets before a time series is worth drawing. */
    private const TIMESERIES_MIN_BUCKETS = 3;

    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private RecordQueryService $records,
        private ?ProposeChangeTool $proposeTool = null,
    ) {}

    public function name(): string
    {
        return 'profile_object';
    }

    public function description(): string
    {
        return 'Profile the REAL data under one object to pick the right visualisations: classifies each field by analytic role (measure/temporal/categorical/identifier/relation) and reports cardinality, numeric min/max/avg/sum, date span and % nulls, then suggests grounded KPIs + charts. Call this BEFORE building a dashboard (after list_dashboard_blueprints) so you map blueprint KPIs to fields the data can actually back, and so you never put a pie chart on a 500-value field. Pass `object_id`. Returns {object_id, total_records, fields:[{id,slug,role,...stats,viz_hint}], recommended_visualizations}.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'object_id' => $schema
                ->string()
                ->description('The id of the object_definition (e.g. obj_01j...) to profile.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $objectId = (string) ($request->all()['object_id'] ?? '');
        if ($objectId === '') {
            return json_encode(['error' => 'object_id is required'], JSON_THROW_ON_ERROR);
        }

        $manifest = $this->proposeTool?->currentManifest()
            ?? $this->manifestService->getActiveManifest($this->appModel);
        if ($manifest === null) {
            return json_encode(['error' => 'App has no active manifest yet'], JSON_THROW_ON_ERROR);
        }

        $object = collect($manifest['objects'] ?? [])->firstWhere('id', $objectId);
        if (! is_array($object)) {
            return json_encode(['error' => "Unknown object_id '{$objectId}'"], JSON_THROW_ON_ERROR);
        }

        $baseQuery = ['object_id' => $objectId];

        try {
            $total = (int) $this->records->aggregate($this->appModel, $baseQuery, 'count', null, $manifest);
        } catch (\Throwable $e) {
            return json_encode(['error' => 'Could not count records: '.$e->getMessage()], JSON_THROW_ON_ERROR);
        }

        $fields = [];
        foreach (($object['fields'] ?? []) as $field) {
            $fields[] = $this->profileField($manifest, $baseQuery, $field, $total);
        }

        return json_encode([
            'object_id' => $objectId,
            'object_name' => $object['name'] ?? $object['slug'] ?? $objectId,
            'total_records' => $total,
            'fields' => $fields,
            'recommended_visualizations' => $this->recommend($object, $fields, $total),
            'note' => $total === 0
                ? 'No records yet — recommendations are based on field types only; verify with simulate_query once data exists.'
                : 'Stats are computed from live data; map blueprint KPIs to the measure/temporal/categorical fields above and verify with simulate_query before propose_change.',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{object_id: string}  $baseQuery
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function profileField(array $manifest, array $baseQuery, array $field, int $total): array
    {
        $type = (string) ($field['type'] ?? 'string');
        $id = (string) ($field['id'] ?? '');
        $out = [
            'id' => $id,
            'slug' => $field['slug'] ?? null,
            'name' => $field['name'] ?? null,
            'type' => $type,
        ];

        $role = $this->roleFor($type);
        $out['role'] = $role;

        if ($total === 0 || $id === '') {
            $out['viz_hint'] = $this->vizHintFor($role, null);

            return $out;
        }

        try {
            switch ($role) {
                case 'measure':
                    $out += $this->measureStats($manifest, $baseQuery, $id, $total);
                    break;
                case 'temporal':
                    $out += $this->temporalStats($manifest, $baseQuery, $field, $total);
                    break;
                case 'categorical':
                case 'identifier':
                    $out += $this->categoricalStats($manifest, $baseQuery, $id, $role, $out);
                    break;
            }
        } catch (\Throwable $e) {
            $out['stats_error'] = $e->getMessage();
        }

        $out['viz_hint'] = $this->vizHintFor($out['role'], $out);

        return $out;
    }

    private function roleFor(string $type): string
    {
        return match (true) {
            in_array($type, self::MEASURE_TYPES, true) => 'measure',
            in_array($type, self::DERIVED_TYPES, true) => 'measure',
            in_array($type, self::TEMPORAL_TYPES, true) => 'temporal',
            in_array($type, self::CATEGORICAL_TYPES, true) => 'categorical',
            $type === 'relation' => 'relation',
            $type === 'string' => 'identifier',
            default => 'detail',
        };
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{object_id: string}  $q
     * @return array<string, mixed>
     */
    private function measureStats(array $manifest, array $q, string $fieldId, int $total): array
    {
        $stats = [];
        foreach (['min', 'max', 'avg', 'sum'] as $agg) {
            $stats[$agg] = round((float) $this->records->aggregate($this->appModel, $q, $agg, $fieldId, $manifest), 2);
        }
        $stats['null_pct'] = $this->nullPct($manifest, $q, $fieldId, $total);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{object_id: string}  $q
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function temporalStats(array $manifest, array $q, array $field, int $total): array
    {
        $id = (string) $field['id'];
        $slug = (string) ($field['slug'] ?? '');

        $earliest = $this->records->query($this->appModel, $q + ['sort' => [['field_id' => $id, 'direction' => 'asc']], 'limit' => 1], $manifest)->first();
        $latest = $this->records->query($this->appModel, $q + ['sort' => [['field_id' => $id, 'direction' => 'desc']], 'limit' => 1], $manifest)->first();

        // Distinct month buckets gauge whether there is enough spread for a trend.
        $buckets = $this->records->groupedAggregate($this->appModel, $q, 'count', null, $id, 'month', $manifest);

        return [
            'earliest' => $earliest?->data[$slug] ?? null,
            'latest' => $latest?->data[$slug] ?? null,
            'month_buckets' => count($buckets),
            'null_pct' => $this->nullPct($manifest, $q, $id, $total),
            'good_for_timeseries' => count($buckets) >= self::TIMESERIES_MIN_BUCKETS,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{object_id: string}  $q
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function categoricalStats(array $manifest, array $q, string $fieldId, string $role, array $current): array
    {
        $groups = $this->records->groupedAggregate($this->appModel, $q, 'count', null, $fieldId, null, $manifest, [], self::CARDINALITY_CAP + 1);

        usort($groups, fn (array $a, array $b): int => ($b['value'] ?? 0) <=> ($a['value'] ?? 0));
        $distinct = count($groups);
        $high = $distinct > self::CARDINALITY_CAP;

        // A "string" field with few distinct values is really a category; with
        // many, it's an identifier/label (not chartable as a breakdown).
        if ($role === 'identifier' && ! $high && $distinct > 0 && $distinct <= 12) {
            $role = 'categorical';
        }

        return [
            'role' => $role,
            'distinct_count' => $high ? self::CARDINALITY_CAP.'+' : $distinct,
            'high_cardinality' => $high,
            'top_values' => array_map(
                fn (array $g): array => ['value' => $g['group'] ?? null, 'count' => $g['value'] ?? 0],
                array_slice($groups, 0, self::TOP_VALUES),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{object_id: string}  $q
     */
    private function nullPct(array $manifest, array $q, string $fieldId, int $total): float|int
    {
        if ($total === 0) {
            return 0;
        }
        $nulls = (int) $this->records->aggregate(
            $this->appModel,
            $q + ['filter' => ['op' => 'is_null', 'field_id' => $fieldId]],
            'count',
            null,
            $manifest,
        );

        return round($nulls / $total * 100, 1);
    }

    /**
     * @param  array<string, mixed>|null  $stats
     */
    private function vizHintFor(string $role, ?array $stats): string
    {
        return match ($role) {
            'measure' => 'KPI (sum/avg in a metric_grid) and the numeric axis of a chart (set field_id + aggregation sum/avg).',
            'temporal' => ($stats['good_for_timeseries'] ?? false)
                ? 'Time series: x_field_id of a line/area chart (runtime buckets by day/week/month), heatmap or calendar.'
                : 'Date field — usable for a time series once more dated records accumulate; for now drives sort/recency and date filters.',
            'categorical' => ($stats['high_cardinality'] ?? false)
                ? 'High cardinality — use a TOP-N hbar (sort the data_source desc, set a limit), not a pie/donut.'
                : 'group_by for a donut/bar breakdown or a kanban (single_select); also a chart series_field_id to split bars.',
            'identifier' => 'Label/title field — use as a table column, card title or chart label, not as a metric or breakdown.',
            'relation' => 'Relation — group/segment by the related entity, or surface related values via a lookup/rollup field.',
            default => 'Detail field — show in tables/record_detail; not used for KPIs or charts.',
        };
    }

    /**
     * Turn the per-field profile into concrete, ranked dashboard suggestions.
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $fields
     * @return list<string>
     */
    private function recommend(array $object, array $fields, int $total): array
    {
        $name = (string) ($object['name'] ?? 'records');
        $recs = ["KPI: total {$name} (count".($total > 0 ? ', with a previous-period compare via sys_created_at' : '').').'];

        $measures = array_values(array_filter($fields, fn (array $f): bool => ($f['role'] ?? '') === 'measure'));
        $temporals = array_values(array_filter($fields, fn (array $f): bool => ($f['role'] ?? '') === 'temporal'));
        $categoricals = array_values(array_filter($fields, fn (array $f): bool => ($f['role'] ?? '') === 'categorical' && ! ($f['high_cardinality'] ?? false)));
        $highCard = array_values(array_filter($fields, fn (array $f): bool => ($f['role'] ?? '') === 'categorical' && ($f['high_cardinality'] ?? false)));

        foreach (array_slice($measures, 0, 2) as $m) {
            $recs[] = "KPI: sum & average of \"{$m['name']}\" (metric_grid items, format ".($m['type'] === 'currency' ? 'currency' : 'number').').';
        }

        // A time series is the single most valuable dashboard chart when dated.
        $dateField = $temporals[0] ?? null;
        if ($dateField !== null && ($dateField['good_for_timeseries'] ?? false)) {
            $recs[] = "Time series (line/area) of count over \"{$dateField['name']}\".";
            if ($measures !== []) {
                $recs[] = "Time series of sum(\"{$measures[0]['name']}\") over \"{$dateField['name']}\" — revenue/volume trend.";
            }
        } else {
            // sys_created_at always exists, so a growth trend is always possible.
            $recs[] = 'Time series (line) of count over sys_created_at — growth trend (system field, always available).';
        }

        foreach (array_slice($categoricals, 0, 3) as $c) {
            $chart = ($c['type'] === 'single_select') ? 'donut/bar' : 'bar';
            $recs[] = "Breakdown ({$chart}) of count by \"{$c['name']}\".";
            if ($measures !== []) {
                $recs[] = "Breakdown (bar) of sum(\"{$measures[0]['name']}\") by \"{$c['name']}\".";
            }
        }

        foreach (array_slice($highCard, 0, 1) as $h) {
            if ($measures !== []) {
                $recs[] = "Top-N (hbar) of sum(\"{$measures[0]['name']}\") by \"{$h['name']}\" — sort desc, set a limit.";
            }
        }

        return $recs;
    }
}
