<?php

namespace App\Services\Manifest;

use Illuminate\Support\Str;

/**
 * Derives a COMPLETE add_dashboard_page spec (KPIs, varied charts, insight
 * scaffolds, the date field) from an object's schema alone — optimization L2.
 * The timing telemetry showed slow models dying in >190s stretches GENERATING
 * the dashboard spec; deriving the same content mechanically lets the model
 * answer prepare_dashboard with `use_suggestion: true` + tiny overrides
 * (~100 tokens) instead of authoring ~2,000. Purely schema-based on purpose:
 * both prepare_dashboard (to show it) and add_dashboard_page (to apply it)
 * recompute it deterministically, so no state passes between tools; categorical
 * charts carry a `limit` so an unknown-cardinality string can never explode a
 * breakdown. The model stays responsible for the HUMAN parts — title, purpose
 * and real insight prose — via overrides.
 */
class DashboardSpecSuggester
{
    private const MAX_KPIS = 6;

    private const MAX_CHARTS = 7;

    /** Breakdown chart types, applied round-robin so variety is structural. */
    private const CATEGORY_CHART_TYPES = ['donut', 'bar', 'hbar', 'radar', 'treemap'];

    private const CATEGORY_LIMIT = 10;

    /**
     * @param  array<string, mixed>  $object  manifest object_definition
     * @return array<string, mixed> spec in add_dashboard_page's input shape
     */
    public function suggest(array $object, string $lang = 'es'): array
    {
        $es = $lang !== 'en';
        $fields = array_values(array_filter($object['fields'] ?? [], 'is_array'));

        $dateField = $this->pickDateField($fields);
        $categoricals = $this->categoricalFields($fields);
        $numerics = array_values(array_filter($fields, fn (array $f): bool => in_array($f['type'] ?? '', ['number', 'currency'], true)));
        $booleans = array_values(array_filter($fields, fn (array $f): bool => ($f['type'] ?? '') === 'boolean'));

        return array_filter([
            'object_slug' => $object['slug'] ?? null,
            'title' => ($es ? 'Análisis de ' : 'Analysis of ').($object['name'] ?? $object['slug'] ?? ''),
            'date_field_id' => $dateField['id'] ?? null,
            'kpis' => $this->suggestKpis($object, $numerics, $booleans, $es),
            'charts' => $this->suggestCharts($dateField, $categoricals, $numerics, $es),
            'insights' => $this->suggestInsights($object, $categoricals, $booleans, $es),
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    private function pickDateField(array $fields): ?array
    {
        $temporal = array_values(array_filter($fields, fn (array $f): bool => in_array($f['type'] ?? '', ['datetime', 'date'], true)));
        if ($temporal === []) {
            return null;
        }

        foreach ($temporal as $field) {
            if (preg_match('/creat|fecha|date|week|semana|time/i', (string) ($field['slug'] ?? '')) === 1) {
                return $field;
            }
        }

        return $temporal[0];
    }

    /**
     * Category candidates: selects always; strings that don't look like ids,
     * names or free text (the chart's `limit` keeps even a mis-guess safe).
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function categoricalFields(array $fields): array
    {
        return array_values(array_filter($fields, function (array $f): bool {
            $type = $f['type'] ?? '';
            if ($type === 'single_select') {
                return true;
            }
            if ($type !== 'string') {
                return false;
            }

            return preg_match('/id$|folio|number|codigo|code|email|phone|tel|url|nombre$|name$|title|titulo|descri|comment|nota|body/i', (string) ($f['slug'] ?? '')) !== 1;
        }));
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $numerics
     * @param  list<array<string, mixed>>  $booleans
     * @return list<array<string, mixed>>
     */
    private function suggestKpis(array $object, array $numerics, array $booleans, bool $es): array
    {
        $kpis = [[
            'label' => ($es ? 'Total ' : 'Total ').Str::lower((string) ($object['name'] ?? 'registros')),
            'aggregation' => 'count',
            'icon' => 'inbox',
        ]];

        foreach ($numerics as $field) {
            if (count($kpis) >= self::MAX_KPIS) {
                break;
            }
            $slug = (string) ($field['slug'] ?? '');
            $name = (string) ($field['name'] ?? $slug);

            if (preg_match('/minut|hora|hour|time|dur|dias|days/i', $slug) === 1) {
                $kpis[] = ['label' => ($es ? 'Mediana ' : 'Median ').$name, 'aggregation' => 'median', 'field_id' => $field['id'], 'icon' => 'clock', 'delta_good' => 'down'];
                if (count($kpis) < self::MAX_KPIS) {
                    $kpis[] = ['label' => 'P95 '.$name, 'aggregation' => 'p95', 'field_id' => $field['id'], 'icon' => 'gauge', 'delta_good' => 'down'];
                }

                continue;
            }

            $rateLike = preg_match('/csat|rating|score|nps|satisf|promedio|avg|rate|pct|percent/i', $slug) === 1;
            $kpis[] = [
                'label' => ($rateLike ? ($es ? 'Promedio ' : 'Average ') : ($es ? 'Suma ' : 'Sum of ')).$name,
                'aggregation' => $rateLike ? 'avg' : 'sum',
                'field_id' => $field['id'],
                'icon' => $rateLike ? 'star' : 'sigma',
            ];
        }

        foreach ($booleans as $field) {
            if (count($kpis) >= self::MAX_KPIS) {
                break;
            }
            $kpis[] = [
                'label' => (string) ($field['name'] ?? $field['slug']),
                'aggregation' => 'count',
                'filter' => ['op' => 'eq', 'field_id' => $field['id'], 'value' => true],
                'icon' => 'alert-triangle',
                'delta_good' => 'down',
            ];
        }

        return $kpis;
    }

    /**
     * @param  array<string, mixed>|null  $dateField
     * @param  list<array<string, mixed>>  $categoricals
     * @param  list<array<string, mixed>>  $numerics
     * @return list<array<string, mixed>>
     */
    private function suggestCharts(?array $dateField, array $categoricals, array $numerics, bool $es): array
    {
        $charts = [];

        if ($dateField !== null) {
            $charts[] = [
                'label' => $es ? 'Volumen por semana' : 'Weekly volume',
                'chart_type' => 'line',
                'aggregation' => 'count',
                'x_field_id' => $dateField['id'],
                'bucket' => 'week',
            ];
        }

        foreach ($categoricals as $i => $field) {
            if (count($charts) >= self::MAX_CHARTS - 1) {
                break;
            }
            $charts[] = [
                'label' => ($es ? 'Por ' : 'By ').Str::lower((string) ($field['name'] ?? $field['slug'])),
                'chart_type' => self::CATEGORY_CHART_TYPES[$i % count(self::CATEGORY_CHART_TYPES)],
                'aggregation' => 'count',
                'group_by_field_id' => $field['id'],
                'limit' => self::CATEGORY_LIMIT,
            ];
        }

        if ($numerics !== [] && $categoricals !== [] && count($charts) < self::MAX_CHARTS) {
            $num = $numerics[0];
            $cat = $categoricals[0];
            $charts[] = [
                'label' => (string) ($num['name'] ?? $num['slug']).($es ? ' por ' : ' by ').Str::lower((string) ($cat['name'] ?? $cat['slug'])),
                'chart_type' => 'box',
                'aggregation' => 'avg',
                'y_field_id' => $num['id'],
                'group_by_field_id' => $cat['id'],
            ];
        }

        if ($dateField !== null && $categoricals !== [] && count($charts) < self::MAX_CHARTS) {
            $cat = $categoricals[0];
            $charts[] = [
                'label' => ($es ? 'Tendencia semanal por ' : 'Weekly trend by ').Str::lower((string) ($cat['name'] ?? $cat['slug'])),
                'chart_type' => 'area',
                'aggregation' => 'count',
                'x_field_id' => $dateField['id'],
                'bucket' => 'week',
                'series_field_id' => $cat['id'],
                'stacked' => true,
            ];
        }

        return $charts;
    }

    /**
     * Insight SCAFFOLDS: correct variants + live computes; the bodies are
     * deliberately plain statements the model should override with real
     * conclusions (that override is ~50 tokens — the cheap part).
     *
     * @param  array<string, mixed>  $object
     * @param  list<array<string, mixed>>  $categoricals
     * @param  list<array<string, mixed>>  $booleans
     * @return list<array<string, mixed>>
     */
    private function suggestInsights(array $object, array $categoricals, array $booleans, bool $es): array
    {
        $objectId = $object['id'] ?? null;
        $insights = [[
            'variant' => 'conclusion',
            'title' => $es ? 'Volumen del periodo' : 'Period volume',
            'body' => $es
                ? 'Registros dentro de la ventana seleccionada — compara contra el periodo anterior para leer la tendencia.'
                : 'Records inside the selected window — compare with the previous period to read the trend.',
            'compute' => ['query' => ['object_id' => $objectId], 'aggregation' => 'count'],
        ]];

        if ($booleans !== []) {
            $flag = $booleans[0];
            $insights[] = [
                'variant' => 'risk',
                'title' => (string) ($flag['name'] ?? $flag['slug']),
                'body' => $es
                    ? 'Casos con esta marca activa en la ventana — priorízalos para evitar escalamientos.'
                    : 'Cases with this flag set inside the window — prioritise them to avoid escalations.',
                'compute' => [
                    'query' => ['object_id' => $objectId, 'filter' => ['op' => 'eq', 'field_id' => $flag['id'], 'value' => true]],
                    'aggregation' => 'count',
                ],
            ];
        }

        if ($categoricals !== []) {
            $cat = $categoricals[0];
            $insights[] = [
                'variant' => 'recommendation',
                'title' => ($es ? 'Concentración por ' : 'Concentration by ').Str::lower((string) ($cat['name'] ?? $cat['slug'])),
                'body' => $es
                    ? 'El valor dominante concentra la mayor parte del volumen — candidato #1 a deflectar o automatizar.'
                    : 'The dominant value concentrates most of the volume — the #1 candidate to deflect or automate.',
            ];
        }

        return $insights;
    }
}
