<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Express\GateRunner;
use App\Services\Express\LabelGrounding;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\Log;

/**
 * G-3: the adversarial pass. Runs AFTER v1 is on screen (its latency is never
 * felt), ideally on a model different from the author (config
 * express.plumbing_model), and may only act through a CLOSED fix menu —
 * remove a redundant chart, rename a label, change a chart type. Everything
 * outside the menu is discarded; no fixes → no version. It raises the
 * ceiling; the floor was already enforced by the compile lints.
 */
#[Queue('ai')]
class VerifyExpressDashboardJob implements ShouldQueue
{
    use Queueable;

    private const VALID_CHART_TYPES = ['bar', 'hbar', 'line', 'area', 'pie', 'donut', 'radar', 'scatter', 'treemap', 'sankey', 'box', 'pareto'];

    private const MAX_FIXES = 5;

    private const MAX_REMOVALS = 2;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public string $runId,
        public ?string $modelOverride = null,
    ) {}

    public function handle(GateRunner $gates, AppManifestService $manifests): void
    {
        $run = PipelineRun::query()->find($this->runId);
        if ($run === null || $run->status !== 'succeeded') {
            return;
        }

        $app = $run->app;
        $user = $run->conversation?->user;
        $pageSlug = $run->result['page']['slug'] ?? null;
        if ($app === null || $user === null || $pageSlug === null) {
            return;
        }

        $manifest = $manifests->getActiveManifest($app);
        $pageIndex = collect($manifest['pages'] ?? [])->search(fn ($p) => ($p['slug'] ?? null) === $pageSlug);
        if ($pageIndex === false) {
            return;
        }
        $page = $manifest['pages'][$pageIndex];

        $summary = $this->summarize($page);

        $result = $gates->run(
            $run,
            'verify',
            <<<'TXT'
Eres un revisor adversarial de dashboards. Recibes el PEDIDO original, el
RESUMEN de la página construida y los NÚMEROS reales que renderizó. Busca:
gráficas redundantes, labels confusos o genéricos, un chart_type que no le
queda a su dato, y números que no cuadran con su label. Devuelve fixes SOLO del
menú: remove_block (bloque redundante), rename_block (label mejor),
change_chart_type (uno de: bar,hbar,line,area,pie,donut,radar,scatter,
treemap,sankey,box,pareto). Máximo 5 fixes; lista vacía si la página está bien.
Nunca inventes block_ids.
TXT,
            json_encode([
                'pedido' => $run->prompt,
                'pagina' => $summary,
                'numeros_renderizados' => array_slice($run->result['rendered'] ?? [], 0, 40),
            ], JSON_UNESCAPED_UNICODE),
            fn ($schema) => [
                'fixes' => $schema->array()->description('[{action: remove_block|rename_block|change_chart_type, block_id, value?}]'),
            ],
            ['fixes' => []],
            $user,
            // A dedicated (cheaper) plumbing model verifies when one is
            // configured; otherwise reuse the model the user built with, so a
            // GLM build's verify stays on GLM instead of silently falling to
            // the expensive builder default.
            $this->verifyModel(),
        );

        $objectsById = collect($manifest['objects'] ?? [])
            ->filter(fn ($o): bool => is_array($o))
            ->keyBy(fn (array $o) => $o['id'] ?? '');
        $fixes = $this->validFixes($result['output']['fixes'] ?? [], $summary, $objectsById->all());
        if ($fixes === []) {
            return;
        }

        $patched = $this->applyFixes($page, $fixes);

        $manifests->applyPatch(
            $app->fresh(),
            [['op' => 'replace', 'path' => '/pages/'.$pageIndex, 'value' => $patched]],
            $user,
            'Afiné el dashboard tras la verificación Express ('.count($fixes).' ajuste(s))',
        );

        // The ops are telemetry: an audit must read WHICH blocks the verifier
        // removed or renamed, not deduce it from holes in the page.
        $run->refresh()->recordGate('verify', ($run->gates['verify'] ?? [])
            + ['fixes' => array_map(fn (array $f): array => array_filter([
                'action' => $f['action'],
                'block_id' => $f['block_id'],
                'value' => is_scalar($f['value'] ?? null) ? (string) $f['value'] : null,
            ], fn ($v) => $v !== null), $fixes)]);

        Log::info('Express verifier applied fixes', ['run_id' => $run->id, 'fixes' => $fixes]);
    }

    /**
     * The model the verify gate runs on: a dedicated plumbing model when the
     * platform configured one (option B — a cheaper, independent second
     * opinion), else the model the user built with. Never the builder default
     * on its own: a GLM build shouldn't have its verify silently billed on the
     * expensive primary just because DASHBOARD_EXPRESS_PLUMBING_MODEL is unset.
     */
    private function verifyModel(): ?string
    {
        $plumbing = trim((string) config('express.plumbing_model'));

        return $plumbing !== '' ? $plumbing : $this->modelOverride;
    }

    /**
     * Flat block summary the gate can reason about (recursing containers).
     *
     * @param  array<string, mixed>  $page
     * @return list<array<string, mixed>>
     */
    private function summarize(array $page): array
    {
        $out = [];
        $walk = function (array $blocks) use (&$walk, &$out): void {
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $out[] = array_filter([
                    'block_id' => $block['id'] ?? null,
                    'type' => $block['type'] ?? null,
                    'label' => $block['label'] ?? ($block['title'] ?? null),
                    'chart_type' => $block['chart_type'] ?? null,
                    // The object this block actually reads — renames are
                    // grounded against ITS fields, not against vibes.
                    'object_id' => $block['data_source']['object_id']
                        ?? ($block['query']['object_id']
                        ?? ($block['stat']['query']['object_id']
                        ?? ($block['compute']['query']['object_id'] ?? null))),
                ], fn ($v) => $v !== null);
                if (is_array($block['blocks'] ?? null)) {
                    $walk($block['blocks']);
                }
            }
        };
        $walk($page['blocks'] ?? []);

        return $out;
    }

    /**
     * Keep only menu-valid fixes referencing real blocks, bounded.
     *
     * @param  array<mixed>  $fixes
     * @param  list<array<string, mixed>>  $summary
     * @return list<array<string, mixed>>
     */
    private function validFixes(array $fixes, array $summary, array $objectsById = []): array
    {
        $byId = collect($summary)->keyBy('block_id');
        $valid = [];
        $removals = 0;

        // Intent-only forms exist BECAUSE the user asked for them (pareto,
        // funnel, heatmap live nowhere else) — the verifier may rename or
        // retype freely elsewhere, but an asked form is not its to take.
        $askedForm = fn (?array $b): bool => in_array($b['chart_type'] ?? null, ['pareto', 'funnel', 'heatmap'], true);
        // Retyping INTO an intent form duplicates it: the audited run ended
        // with two paretos because the verifier promoted an hbar while the
        // asked pareto already existed. One intent form per board.
        $formsOnBoard = collect($summary)->pluck('chart_type')->filter()->countBy();

        foreach ($fixes as $fix) {
            if (! is_array($fix) || count($valid) >= self::MAX_FIXES) {
                continue;
            }
            $action = $fix['action'] ?? null;
            $blockId = $fix['block_id'] ?? null;
            $block = is_string($blockId) ? $byId->get($blockId) : null;
            if ($block === null) {
                continue;
            }

            $ok = match ($action) {
                // Same bar as G-2a: a rename claiming a dimension must point
                // at data that carries it («Pareto de Motivos» over FCR
                // category keys walked in exactly here).
                'rename_block' => is_string($fix['value'] ?? null) && trim($fix['value']) !== ''
                    && LabelGrounding::grounded((string) $fix['value'], $objectsById[$block['object_id'] ?? ''] ?? null),
                'change_chart_type' => ($block['type'] ?? null) === 'chart'
                    && ! $askedForm($block)
                    && in_array($fix['value'] ?? null, self::VALID_CHART_TYPES, true)
                    && ! (in_array($fix['value'], ['pareto', 'funnel', 'heatmap'], true)
                        && ($formsOnBoard[$fix['value']] ?? 0) > 0),
                'remove_block' => in_array($block['type'] ?? null, ['chart', 'insight'], true)
                    && ! $askedForm($block)
                    && $removals < self::MAX_REMOVALS,
                default => false,
            };

            if ($ok) {
                if ($action === 'remove_block') {
                    $removals++;
                }
                $valid[] = ['action' => $action, 'block_id' => $blockId, 'value' => $fix['value'] ?? null];
            }
        }

        return $valid;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $fixes
     * @return array<string, mixed>
     */
    private function applyFixes(array $page, array $fixes): array
    {
        // groupBy, not keyBy: a block may receive several fixes (rename AND a
        // chart-type change) — all of them apply; a remove trumps the rest.
        $byId = collect($fixes)->groupBy('block_id');

        $walk = function (array $blocks) use (&$walk, $byId): array {
            $result = [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    $result[] = $block;

                    continue;
                }
                $blockFixes = $byId->get($block['id'] ?? '') ?? collect();
                if ($blockFixes->contains(fn (array $f): bool => $f['action'] === 'remove_block')) {
                    continue;
                }
                foreach ($blockFixes as $fix) {
                    if ($fix['action'] === 'rename_block') {
                        if (array_key_exists('label', $block) || ! array_key_exists('title', $block)) {
                            $block['label'] = $fix['value'];
                        } else {
                            $block['title'] = $fix['value'];
                        }
                    }
                    if ($fix['action'] === 'change_chart_type') {
                        $block['chart_type'] = $fix['value'];
                    }
                }
                if (is_array($block['blocks'] ?? null)) {
                    $block['blocks'] = $walk($block['blocks']);
                    // A container whose every child was removed is a hole in
                    // the page, not a block (observed: two empty shells after
                    // two remove_block fixes).
                    if (($block['type'] ?? null) === 'container' && $block['blocks'] === []) {
                        continue;
                    }
                }
                $result[] = $block;
            }

            // A heading whose section lost every block (the removed card was
            // the whole «Lecturas clave» band) is an orphan — drop it.
            $pruned = [];
            foreach ($result as $i => $block) {
                $isHeading = is_array($block) && ($block['type'] ?? null) === 'heading';
                $next = $result[$i + 1] ?? null;
                if ($isHeading && ($next === null || (is_array($next) && ($next['type'] ?? null) === 'heading'))) {
                    continue;
                }
                $pruned[] = $block;
            }

            return $pruned;
        };

        $page['blocks'] = $walk($page['blocks'] ?? []);

        return $page;
    }
}
