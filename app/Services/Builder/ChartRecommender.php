<?php

namespace App\Services\Builder;

use App\Facades\TenantCache;
use App\Models\App;
use App\Models\User;
use App\Services\Analyst\AnalystCore;
use App\Services\Analyst\FindingBlock;
use App\Services\Analyst\SemanticKey;

/**
 * The App Builder's face of the analyst — the intelligence behind «Agregar
 * gráfica».
 *
 * The analysis itself lives in {@see AnalystCore}, which knows nothing about
 * manifests, pages or blocks. This adapter owns the three things that are only
 * true of the builder panel:
 *
 * - what the board ALREADY shows, read off the page's blocks and handed to the
 *   core as semantic keys to exclude, so it never proposes a cut that's up;
 * - the recommendation id, scoped to this app and page;
 * - the proposed spec, cached under that id so «Agregar» inserts exactly what
 *   was previewed without trusting a client round-trip.
 *
 * Another surface (a deck, an agent, MCP) asks the core directly and adapts the
 * findings its own way.
 */
class ChartRecommender
{
    /** Cached recommendation spec TTL (seconds) — long enough for a build session. */
    private const SPEC_TTL = 1800;

    public function __construct(private AnalystCore $core) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $page
     * @return array{domain: array{sector: string, label: string}, sources: int, total_rows: int, recommendations: list<array<string, mixed>>, gaps: list<array{text: string}>, data_quality: list<array{level: string, text: string}>, sources_detail: list<array<string, mixed>>, source_suggestions: list<array<string, mixed>>}
     */
    public function recommend(App $app, array $manifest, array $page, ?User $actor, string $lang = 'es'): array
    {
        $names = SemanticKey::fieldNames($manifest);
        $hints = SemanticKey::objectHints($manifest);
        $shown = $this->shownOnPage($page['blocks'] ?? [], $names, $hints);

        $analysis = $this->core->analyze($app, $manifest, $actor, $lang, $shown);

        $recommendations = array_map(
            fn (array $finding): array => $this->present($app, $page, $finding),
            $analysis['findings'],
        );

        unset($analysis['findings']);

        return ['recommendations' => $recommendations] + $analysis;
    }

    /**
     * The cached chart spec for a recommendation id, or null if it expired.
     *
     * @return array{chart: array<string, mixed>, object_id: string}|null
     */
    public function specFor(App $app, string $recId): ?array
    {
        $spec = TenantCache::get($this->specKey($app, $recId));

        return is_array($spec) ? $spec : null;
    }

    /**
     * A finding as a recommendation card: an id the panel can post back, and the
     * spec behind it parked in the cache.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function present(App $app, array $page, array $finding): array
    {
        $recId = substr(sha1($app->id.'|'.($page['id'] ?? '').'|'.$finding['identity']), 0, 16);
        $rendered = FindingBlock::forFinding($finding);
        $chart = $finding['chart'] ?? [];
        unset($chart['__gauge']);

        // The finished BLOCK is what gets cached — not a spec to be rebuilt at
        // insert time. «Agregar» then adds exactly what was previewed, and there
        // is only one place that decides what an analysis looks like on a board.
        TenantCache::put($this->specKey($app, $recId), [
            'kind' => $rendered['type'],
            'label' => $rendered['label'],
            'block' => $rendered['block'],
            // Kept for callers that read the raw analysis behind the card.
            'chart' => $chart,
            'object_id' => FindingBlock::objectId($finding),
        ], self::SPEC_TTL);

        return [
            'id' => $recId,
            'kicker' => $finding['kicker'],
            'title' => $finding['title'],
            'why' => $finding['why'],
            'form' => match (true) {
                $rendered['type'] === 'insight' => 'insight',
                $rendered['type'] === 'gauge' => 'gauge',
                $rendered['type'] === 'stat' => 'stat',
                // A combo renders as a dual-axis chart, not as the bar its
                // chart_type nominally says.
                isset($chart['series']) => 'combo',
                default => $chart['chart_type'] ?? 'bar',
            },
            'flag' => $finding['flag'],
            'preview' => $finding['preview'],
        ];
    }

    /**
     * The semantic keys of the charts and gauges already on the page — what the
     * core must not propose again. Walked recursively into containers.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string>  $names
     * @param  array<string, string>  $hints
     * @return list<string>
     */
    private function shownOnPage(array $blocks, array $names, array $hints): array
    {
        $seen = [];
        $walk = function (array $blocks) use (&$walk, &$seen, $names, $hints): void {
            foreach ($blocks as $b) {
                if (! is_array($b)) {
                    continue;
                }
                if (($b['type'] ?? null) === 'chart') {
                    $seen[SemanticKey::forChart((string) ($b['data_source']['object_id'] ?? ''), array_filter([
                        'chart_type' => $b['chart_type'] ?? null,
                        'group_by_field_id' => $b['group_by_field_id'] ?? null,
                        'x_field_id' => $b['x_field_id'] ?? null,
                        'y_field_id' => $b['y_field_id'] ?? null,
                        // A composition, a flow and a seasonal cut are told apart
                        // by these — without them a stacked bar reads as a plain
                        // breakdown and never dedupes.
                        'series_field_id' => $b['series_field_id'] ?? null,
                        'bucket' => $b['bucket'] ?? null,
                    ], fn ($v) => $v !== null), $names, $hints)] = true;
                }
                if (($b['type'] ?? null) === 'gauge') {
                    $seen[SemanticKey::forChart('', ['__gauge' => true, 'field_id' => $b['field_id'] ?? ''], $names, $hints)] = true;
                }
                if (is_array($b['blocks'] ?? null)) {
                    $walk($b['blocks']);
                }
            }
        };
        $walk($blocks);

        return array_keys($seen);
    }

    private function specKey(App $app, string $recId): string
    {
        return 'chartrec:spec:'.$app->id.':'.$recId;
    }
}
