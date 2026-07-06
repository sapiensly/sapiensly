<?php

namespace App\Services\Express;

use App\Models\App;
use App\Models\User;
use App\Services\Records\BlockDataResolver;

/**
 * Renders a dashboard page FOR REAL (through the same BlockDataResolver the
 * runtime uses) and audits the numbers that came back — the difference
 * between "the manifest validates" and "what the user will see makes sense".
 * Deterministic checks only:
 *
 *  - a KPI that resolved to null, or errored;
 *  - a chart with fewer than 2 points/slices, or whose values are all equal
 *    (a flat line pretending to be a trend);
 *  - blocks that failed to load at all.
 *
 * Returns per-block issues tagged repairable (safe to drop) or note-only, a
 * compact numeric summary for the adversarial verifier, and the score inputs
 * Q5 tracks. Used by VerifyRenderPhase (repair loop) and the benchmark.
 */
class QualityAudit
{
    public function __construct(private readonly BlockDataResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     issues: list<array{block_id: string, kind: string, detail: string, repairable: bool}>,
     *     summary: list<array<string, mixed>>,
     *     blocks_audited: int,
     * }
     */
    public function audit(App $app, array $page, array $manifest, User $actor): array
    {
        $data = $this->resolver->resolve($app, $page['blocks'] ?? [], $manifest, [
            'current_user' => ['id' => $actor->id, 'email' => $actor->email],
            'params' => [],
            '__actor' => $actor,
        ]);

        $issues = [];
        $summary = [];
        $audited = 0;

        $walk = function (array $blocks) use (&$walk, &$issues, &$summary, &$audited, $data): void {
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                if (is_array($block['blocks'] ?? null)) {
                    $walk($block['blocks']);
                }

                $blockId = (string) ($block['id'] ?? '');
                $type = (string) ($block['type'] ?? '');
                $resolved = $data[$blockId] ?? null;
                if ($resolved === null || ! in_array($type, ['chart', 'stat', 'metric_grid', 'insight'], true)) {
                    continue;
                }
                $audited++;

                if (isset($resolved['error'])) {
                    $issues[] = [
                        'block_id' => $blockId, 'kind' => 'load_error',
                        'detail' => (string) $resolved['error'], 'repairable' => false,
                    ];

                    continue;
                }

                if ($type === 'chart') {
                    $this->auditChart($block, $resolved, $issues, $summary);
                }
                if ($type === 'metric_grid') {
                    $this->auditMetricGrid($block, $resolved, $issues, $summary);
                }
                if ($type === 'stat') {
                    $this->auditStat($block, $resolved, $issues, $summary);
                }
            }
        };
        $walk($page['blocks'] ?? []);

        return ['issues' => $issues, 'summary' => $summary, 'blocks_audited' => $audited];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $resolved
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $summary
     */
    private function auditChart(array $block, array $resolved, array &$issues, array &$summary): void
    {
        $rows = is_array($resolved['rows'] ?? null) ? $resolved['rows'] : [];
        $blockId = (string) $block['id'];
        $label = (string) ($block['label'] ?? 'chart');

        $points = $rows;
        $count = count($points);
        if ($count < 2) {
            $issues[] = [
                'block_id' => $blockId, 'kind' => 'too_few_points',
                'detail' => "«{$label}» resolvió {$count} punto(s) — no hay nada que graficar.",
                'repairable' => true,
            ];

            return;
        }

        // Flat series: every numeric y is identical.
        $yField = $block['y_field_id'] ?? null;
        $numbers = collect($points)
            ->map(function (array $r) use ($yField): mixed {
                $data = $r['data'] ?? [];
                if ($yField !== null) {
                    // y values arrive keyed by slug; take any numeric.
                    return collect($data)->first(fn ($v) => is_numeric($v));
                }

                return collect($data)->first(fn ($v) => is_numeric($v));
            })
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (float) $v)
            ->values();

        if ($numbers->count() >= 3 && $numbers->unique()->count() === 1) {
            $issues[] = [
                'block_id' => $blockId, 'kind' => 'flat_series',
                'detail' => "«{$label}»: todos los valores son idénticos ({$numbers->first()}).",
                'repairable' => true,
            ];
        }

        $summary[] = [
            'block' => $label,
            'type' => (string) ($block['chart_type'] ?? 'chart'),
            'points' => $count,
            'min' => $numbers->min(),
            'max' => $numbers->max(),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $resolved
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $summary
     */
    private function auditMetricGrid(array $block, array $resolved, array &$issues, array &$summary): void
    {
        foreach ($block['items'] ?? [] as $item) {
            $itemId = (string) ($item['id'] ?? '');
            $label = (string) ($item['label'] ?? 'kpi');
            $payload = $resolved['items'][$itemId] ?? null;

            if (! is_array($payload) || isset($payload['error'])) {
                $issues[] = [
                    'block_id' => (string) $block['id'], 'kind' => 'kpi_error',
                    'detail' => "KPI «{$label}»: ".(is_array($payload) ? (string) ($payload['error'] ?? '') : 'sin datos'),
                    'repairable' => false,
                ];

                continue;
            }

            $value = $payload['value'] ?? null;
            if ($value === null) {
                $issues[] = [
                    'block_id' => (string) $block['id'], 'kind' => 'kpi_null',
                    'detail' => "KPI «{$label}» resolvió null.",
                    'repairable' => false,
                ];

                continue;
            }

            $summary[] = ['kpi' => $label, 'value' => $value];
        }
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $resolved
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $summary
     */
    private function auditStat(array $block, array $resolved, array &$issues, array &$summary): void
    {
        $label = (string) ($block['label'] ?? 'stat');
        $value = $resolved['value'] ?? null;

        if ($value === null) {
            $issues[] = [
                'block_id' => (string) $block['id'], 'kind' => 'kpi_null',
                'detail' => "KPI «{$label}» resolvió null.",
                'repairable' => false,
            ];

            return;
        }

        $summary[] = ['kpi' => $label, 'value' => $value];
    }
}
