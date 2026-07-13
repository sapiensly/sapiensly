<?php

namespace App\Services\Analyst;

/**
 * A finding as a manifest block — the App-Builder shape of an analysis.
 *
 * The core proposes an analysis; this says what it looks like on a board: a
 * `chart`, a `gauge`, or an `insight` when the value is in the reading rather
 * than the picture (a cross-source join, a derived ratio). The block carries no
 * `id` — whoever inserts it mints one, because ids belong to the manifest, not
 * to the analysis.
 */
class FindingBlock
{
    /**
     * @param  array<string, mixed>  $finding  as returned by {@see AnalystCore::analyze}
     * @return array{type: string, label: string, block: array<string, mixed>}
     */
    public static function forFinding(array $finding): array
    {
        $objectId = self::objectId($finding);

        if (isset($finding['insight'])) {
            $insight = $finding['insight'];

            return [
                'type' => 'insight',
                'label' => (string) $insight['title'],
                'block' => [
                    'type' => 'insight',
                    'title' => $insight['title'],
                    'body' => $insight['body'],
                    'variant' => $insight['variant'] ?? 'conclusion',
                ],
            ];
        }

        $chart = $finding['chart'];

        if (($chart['__gauge'] ?? false) === true) {
            return [
                'type' => 'gauge',
                'label' => (string) $chart['label'],
                'block' => [
                    'type' => 'gauge',
                    'label' => $chart['label'],
                    'query' => ['object_id' => $objectId],
                    'field_id' => $chart['field_id'],
                    'aggregation' => $chart['aggregation'] ?? 'avg',
                    'max_value' => $chart['max_value'],
                    'format' => $chart['format'] ?? 'number',
                    'style' => ['col_span' => 4, 'min_height' => 320],
                ],
            ];
        }

        return [
            'type' => 'chart',
            'label' => (string) $chart['label'],
            'block' => array_filter([
                'type' => 'chart',
                'label' => $chart['label'],
                'description' => $chart['description'] ?? null,
                'chart_type' => $chart['chart_type'],
                'x_field_id' => $chart['x_field_id'] ?? null,
                'group_by_field_id' => $chart['group_by_field_id'] ?? null,
                'y_field_id' => $chart['y_field_id'] ?? null,
                'aggregation' => $chart['aggregation'] ?? 'count',
                'bucket' => $chart['bucket'] ?? null,
                'data_source' => ['object_id' => $objectId, 'limit' => self::rowLimit($finding, $chart)],
            ], fn ($v) => $v !== null),
        ];
    }

    /**
     * How many rows the chart must fetch to be TRUE.
     *
     * A chart block aggregates client-side over the rows it is given, so the
     * limit decides whether the picture is right. A connected breakdown source
     * returns one row per category — already aggregated — so a dozen is the
     * whole story. An internal object returns one row per RECORD, so the same
     * dozen would chart twelve tickets out of five hundred: the platform's
     * native-chart window (AppScaffolder::DASHBOARD_ROW_LIMIT) is what makes it
     * honest.
     *
     * @param  array<string, mixed>  $finding
     * @param  array<string, mixed>  $chart
     */
    private static function rowLimit(array $finding, array $chart): int
    {
        $connected = ($finding['connected'] ?? true) === true;

        if (! $connected) {
            return 500;
        }

        return isset($chart['x_field_id']) ? 500 : 12;
    }

    /**
     * The object a finding reads. Its identity always starts with the object id
     * (a json array, or `gauge|{objectId}|…`); a cross-source or derived finding
     * spans objects and has none.
     *
     * @param  array<string, mixed>  $finding
     */
    public static function objectId(array $finding): string
    {
        $identity = (string) ($finding['identity'] ?? '');
        if (str_starts_with($identity, 'gauge|')) {
            return explode('|', $identity)[1] ?? '';
        }
        $decoded = json_decode($identity, true);

        return is_array($decoded) ? (string) ($decoded[0] ?? '') : '';
    }
}
