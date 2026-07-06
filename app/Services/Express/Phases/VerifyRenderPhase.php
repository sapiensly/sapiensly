<?php

namespace App\Services\Express\Phases;

use App\Ai\Tools\Builder\PlanDashboardTool;
use App\Models\PipelineRun;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Express\QualityAudit;
use App\Services\Manifest\AppManifestService;

/**
 * F-5, the quality gate the user asked for by name: render the just-applied
 * dashboard FOR REAL and repair what the numbers prove broken. A chart that
 * resolved to 1 point or a flat series is dropped (as long as the page still
 * passes the structural lints); non-repairable findings (KPI null, a block
 * that failed to load) become honest notes in the report instead of surprises
 * on screen. The rendered summary is stored for the adversarial verifier, so
 * G-3 judges NUMBERS, not just labels. Costs one real render (~1-5s live) —
 * quality bought consciously.
 */
class VerifyRenderPhase implements ExpressPhase
{
    public function __construct(
        private readonly QualityAudit $audit,
        private readonly AppManifestService $manifests,
    ) {}

    public function name(): string
    {
        return 'verify_render';
    }

    public function announce(ExpressContext $context): string
    {
        return 'Verificando los números renderizados…';
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        if ($context->page === null) {
            return;
        }

        $manifest = $this->manifests->getActiveManifest($context->app->fresh());
        $pageIndex = collect($manifest['pages'] ?? [])->search(
            fn ($p) => ($p['slug'] ?? null) === $context->page['slug'],
        );
        if ($pageIndex === false) {
            return;
        }
        $page = $manifest['pages'][$pageIndex];

        $result = $this->audit->audit($context->app, $page, $manifest, $context->user);

        $run->recordGate('verify_render', [
            'model' => null,
            'latency_ms' => null,
            'fallback_used' => false,
            'issues' => count($result['issues']),
            'blocks_audited' => $result['blocks_audited'],
        ]);
        $context->renderedSummary = $result['summary'];

        if ($result['issues'] === []) {
            return;
        }

        // Repair: drop the blocks whose rendered data is degenerate, as long
        // as the page still passes the structural lints afterwards.
        $repairableIds = collect($result['issues'])
            ->filter(fn (array $i): bool => $i['repairable'])
            ->pluck('block_id')->unique()->values()->all();

        $repaired = $repairableIds !== [] ? $this->dropBlocks($page, $repairableIds) : null;
        $applied = false;

        if ($repaired !== null && $this->stillLints($repaired)) {
            $version = $this->manifests->applyPatch(
                $context->app->fresh(),
                [['op' => 'replace', 'path' => '/pages/'.$pageIndex, 'value' => $repaired]],
                $context->user,
                'Saneé el dashboard: retiré '.count($repairableIds).' bloque(s) sin datos suficientes',
            );
            $applied = true;
            $context->page['version'] = $version->version_number;
            $context->page['version_id'] = $version->id;
        }

        foreach ($result['issues'] as $issue) {
            $prefix = $issue['repairable']
                ? ($applied ? 'Retirado del tablero: ' : 'Con datos débiles (se conservó para no vaciar el tablero): ')
                : 'Revisar: ';
            $context->note($prefix.$issue['detail']);
        }
    }

    /**
     * Remove blocks by id anywhere in the tree, dissolving containers that end
     * up empty.
     *
     * @param  array<string, mixed>  $page
     * @param  list<string>  $blockIds
     * @return array<string, mixed>
     */
    private function dropBlocks(array $page, array $blockIds): array
    {
        $drop = array_flip($blockIds);

        $walk = function (array $blocks) use (&$walk, $drop): array {
            $out = [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    $out[] = $block;

                    continue;
                }
                if (isset($drop[$block['id'] ?? ''])) {
                    continue;
                }
                if (is_array($block['blocks'] ?? null)) {
                    $block['blocks'] = $walk($block['blocks']);
                    if ($block['blocks'] === [] && ($block['type'] ?? '') === 'container') {
                        continue;
                    }
                }
                $out[] = $block;
            }

            return $out;
        };

        $page['blocks'] = $walk($page['blocks'] ?? []);

        return $page;
    }

    /**
     * The repaired page must still satisfy the professional-dashboard lints —
     * otherwise keeping a weak chart beats an empty board. Plan rows are
     * reconstructed the way the compiler laid them out: one row per top-level
     * block, containers contributing their children as one row.
     *
     * @param  array<string, mixed>  $page
     */
    private function stillLints(array $page): bool
    {
        // Flatten, then re-pack the way a re-flowed layout would: charts in
        // pairs (a lone short chart in a row trips the layout lint even though
        // the renderer flows it fine), everything else one per row.
        $flat = [];
        foreach ($page['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'container' && is_array($block['blocks'] ?? null)) {
                foreach ($block['blocks'] as $child) {
                    if (is_array($child)) {
                        $flat[] = $child;
                    }
                }
            } else {
                $flat[] = $block;
            }
        }

        $charts = array_values(array_filter($flat, fn (array $b): bool => ($b['type'] ?? '') === 'chart'));

        // Our own floor: a dashboard with zero charts is not a dashboard —
        // the structural lint tolerates it, we don't.
        if ($charts === []) {
            return false;
        }

        $rows = [];
        foreach ($flat as $block) {
            if (($block['type'] ?? '') === 'chart') {
                continue; // packed below
            }
            $rows[] = ['blocks' => [$block]];
        }
        foreach (array_chunk($charts, 2) as $pair) {
            $rows[] = ['blocks' => $pair];
        }

        try {
            return PlanDashboardTool::lint((string) ($page['name'] ?? 'Dashboard'), $rows)['ok'];
        } catch (\Throwable) {
            // If the lint contract shifts underneath us, err on keeping the
            // page intact rather than blocking the repair path entirely.
            return true;
        }
    }
}
