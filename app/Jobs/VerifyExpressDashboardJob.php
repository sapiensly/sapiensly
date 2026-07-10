<?php

namespace App\Jobs;

use App\Models\PipelineRun;
use App\Services\Express\GateRunner;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * G-3: the adversarial pass. Runs AFTER v1 is on screen (its latency is never
 * felt), ideally on a model different from the author (config
 * express.plumbing_model), and may only act through a CLOSED fix menu —
 * remove a redundant chart, rename a label, change a chart type. Everything
 * outside the menu is discarded; no fixes → no version. It raises the
 * ceiling; the floor was already enforced by the compile lints.
 */
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

    public function viaQueue(): string
    {
        return 'ai';
    }

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

        $fixes = $this->validFixes($result['output']['fixes'] ?? [], $summary);
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
    private function validFixes(array $fixes, array $summary): array
    {
        $byId = collect($summary)->keyBy('block_id');
        $valid = [];
        $removals = 0;

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
                'rename_block' => is_string($fix['value'] ?? null) && trim($fix['value']) !== '',
                'change_chart_type' => ($block['type'] ?? null) === 'chart'
                    && in_array($fix['value'] ?? null, self::VALID_CHART_TYPES, true),
                'remove_block' => in_array($block['type'] ?? null, ['chart', 'insight'], true)
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
                }
                $result[] = $block;
            }

            return $result;
        };

        $page['blocks'] = $walk($page['blocks'] ?? []);

        return $page;
    }
}
