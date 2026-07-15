<?php

namespace App\Services\Express\Phases;

use App\Models\PipelineRun;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * F-2: author one connected object per chosen tool (server-side, ~1-3s each —
 * no model) and bank them ALL as ONE applied version, so the manifest history
 * reads "objetos del dashboard" instead of N micro-versions. A tool that
 * fails is excluded and reported; zero successes fails the run.
 */
class AcquireObjectsPhase implements ExpressPhase
{
    public function __construct(
        private readonly ConnectedObjectAuthoring $authoring,
        private readonly AppManifestService $manifests,
    ) {}

    public function name(): string
    {
        return 'acquire_objects';
    }

    public function announce(ExpressContext $context): string
    {
        $n = count($context->chosenTools) + count($context->chosenCuts);

        return $context->tr('Modeling :count connected object(s) from the source…', ['count' => $n]);
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $manifest = $this->manifests->getActiveManifest($context->app);
        if (! is_array($manifest)) {
            throw new \RuntimeException('The app has no active manifest.');
        }

        $ops = [];
        $summaries = [];
        $baseArgs = [];
        $outcomes = [];

        // Absorb one authored result for its target: telemetry, the applied op,
        // the in-memory draft (slug uniqueness), and — for a base read — the
        // resolved args a later cut dedups against. A per-target failure is
        // noted and skipped, exactly like the serial path; zero successes fails
        // the run below.
        $absorb = function (array $target, array $authored) use (&$ops, &$summaries, &$baseArgs, &$outcomes, &$manifest, $context): void {
            $toolName = (string) $target['tool'];
            $label = ($target['cut'] !== null ? $target['cut'].' @' : '').$toolName;

            if (($authored['ok'] ?? false) !== true) {
                $error = (string) ($authored['error'] ?? $context->tr('unknown error'));
                $targetLabel = $target['cut'] !== null
                    ? $context->tr('the :cut cut of :tool', ['cut' => $target['cut'], 'tool' => $toolName])
                    : $context->tr('the :tool tool', ['tool' => $toolName]);
                $this->noteReadFailure($context, $target, $targetLabel, $error);
                $outcomes[] = ['target' => $label, 'outcome' => 'failed', 'error' => Str::limit($error, 160, '…')];

                return;
            }

            $object = $authored['object'];
            $outcomes[] = ['target' => $label, 'outcome' => 'ok', 'rows' => count($authored['rows'] ?? [])];
            if ($target['cut'] === null) {
                $baseArgs[$toolName] = $object['source']['operations']['list']['arguments'] ?? [];
            }
            $manifest['objects'][] = $object;
            $ops[] = ['op' => 'add', 'path' => '/objects/-', 'value' => $object];
            $summaries[] = $authored['summary'];
            $context->objects[] = $object;
            $context->rowsByObject[$object['id']] = $authored['rows'];
        };

        // --- Base reads: every chosen tool's DEFAULT cut, POOLED into one
        //     round-trip, then absorbed in order so baseArgs is populated before
        //     any cut dedups against it. ---
        $baseTargets = array_map(
            fn (string $name): array => ['tool' => $name, 'arguments' => null, 'cut' => null],
            $context->chosenTools,
        );
        $baseResults = $this->authoring->authorMany(
            $context->user, $context->integration, array_map($this->specFor(...), $baseTargets), $manifest,
        );
        foreach ($baseTargets as $i => $target) {
            $absorb($target, $baseResults[$i] ?? ['ok' => false, 'error' => $context->tr('no result from the batch')]);
        }

        // --- Enum cuts: re-read a chosen tool with one argument swapped
        //     ("dimension: cause"). A cut whose swapped arg equals what the base
        //     read already resolved to is the same data twice — skipped. The
        //     survivors pool against the GROWN manifest (unique slugs vs base),
        //     and telemetry stays in chosen-cut order (skips interleaved). ---
        $cutSurvivors = [];
        $cutPlan = [];
        foreach ($context->chosenCuts as $cut) {
            $toolName = (string) $cut['tool'];
            $duplicate = false;
            if (is_array($baseArgs[$toolName] ?? null)) {
                $cutArgs = is_array($cut['arguments']) ? $cut['arguments'] : [];
                $duplicate = $cutArgs !== [] && collect($cutArgs)->every(
                    fn ($v, $k): bool => (string) ($baseArgs[$toolName][$k] ?? '') === (string) $v,
                );
            }
            $cutPlan[] = ['target' => $cut, 'skip' => $duplicate];
            if (! $duplicate) {
                $cutSurvivors[] = $cut;
            }
        }
        $cutResults = $cutSurvivors === [] ? [] : $this->authoring->authorMany(
            $context->user, $context->integration, array_map($this->specFor(...), $cutSurvivors), $manifest,
        );
        $ri = 0;
        foreach ($cutPlan as $plan) {
            $cut = $plan['target'];
            if ($plan['skip']) {
                $context->note($context->tr('Cut :cut skipped: it duplicates the base read of :tool.', [
                    'cut' => $cut['cut'],
                    'tool' => (string) $cut['tool'],
                ]));
                $outcomes[] = ['target' => $cut['cut'].' @'.$cut['tool'], 'outcome' => 'skipped_duplicate'];

                continue;
            }
            $absorb($cut, $cutResults[$ri++] ?? ['ok' => false, 'error' => $context->tr('no result from the batch')]);
        }

        // Double window: sample every acquired tool ONE span back so the facts
        // can say "+18% vs periodo anterior" instead of a static number. Pooled
        // into one round-trip (was N serial reads); best-effort — a window-less
        // tool or a failed read just yields no delta, exactly as before.
        foreach ($this->authoring->previousWindowRowsMany($context->user, $context->integration, $context->objects) as $objectId => $previous) {
            $context->previousRowsByObject[$objectId] = $previous;
        }

        // Every planned read's fate is telemetry: the priority cut vanished
        // across three prod runs before anyone could say WHERE.
        $run->recordGate('acquire', ['targets' => $outcomes]);

        if ($ops === []) {
            throw new \RuntimeException('Ninguno de los tools elegidos devolvió datos utilizables.');
        }

        $version = $this->manifests->applyPatch(
            $context->app->fresh(),
            $ops,
            $context->user,
            implode(' · ', $summaries),
        );

        $context->note($context->tr('Objects applied in version v:version.', ['version' => $version->version_number]));
    }

    /**
     * The author spec for one target (a base tool or an enum cut): the tool
     * name, its swapped arguments, and a cut's slice name. One place so the
     * base and cut batches build specs identically.
     *
     * @param  array{tool: string, arguments: ?array<string, string>, cut: ?string}  $target
     * @return array<string, mixed>
     */
    private function specFor(array $target): array
    {
        $toolName = (string) $target['tool'];

        return array_filter([
            'tool_name' => $toolName,
            'arguments' => $target['arguments'],
            'object_name' => $target['cut'] !== null
                ? Str::headline((string) preg_replace(['/^get[-_]/i', '/[-_]?tool$/i'], '', $toolName)).' · '.Str::headline((string) $target['cut'])
                : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * A failed read must be VISIBLE, not a silent hole: prod lost the
     * priority cut twice in a row (the source rejects dimension=priority)
     * and only object-count arithmetic revealed it. Cuts are the user's own
     * named dimensions, so their failures also ride the report caveats.
     *
     * @param  array{tool: string, arguments: ?array<string, string>, cut: ?string}  $target
     */
    private function noteReadFailure(ExpressContext $context, array $target, string $targetLabel, string $error): void
    {
        $context->note($context->tr(':label could not be read: :error', ['label' => $targetLabel, 'error' => $error]));
        if ($target['cut'] !== null) {
            $context->coverageNotes[] = $context->tr("**:cut** couldn't be read from the source (:error) — that breakdown isn't on the dashboard.", [
                'cut' => $target['cut'],
                'error' => Str::limit($error, 120, '…'),
            ]);
        }
    }
}
