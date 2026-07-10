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

        return "Modelando {$n} objeto(s) conectado(s) desde la fuente…";
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $manifest = $this->manifests->getActiveManifest($context->app);
        if (! is_array($manifest)) {
            throw new \RuntimeException('The app has no active manifest.');
        }

        // Every chosen tool acquires its DEFAULT cut; enum cuts re-read a
        // chosen tool with one argument swapped ("dimension: cause") and a
        // name that says which slice this object is.
        $targets = array_map(
            fn (string $name): array => ['tool' => $name, 'arguments' => null, 'cut' => null],
            $context->chosenTools,
        );
        foreach ($context->chosenCuts as $cut) {
            $targets[] = $cut;
        }

        $ops = [];
        $summaries = [];
        foreach ($targets as $target) {
            $toolName = (string) $target['tool'];
            $spec = array_filter([
                'tool_name' => $toolName,
                'arguments' => $target['arguments'],
                'object_name' => $target['cut'] !== null
                    ? Str::headline((string) preg_replace(['/^get[-_]/i', '/[-_]?tool$/i'], '', $toolName)).' · '.Str::headline((string) $target['cut'])
                    : null,
            ], fn ($v) => $v !== null);

            // One tool that throws (a slow/oversized source, a transport error)
            // must not abort the whole build — note it and move on, exactly like
            // an ok:false. Only ZERO successes fails the run (below).
            try {
                $authored = $this->authoring->author($context->user, $context->integration, $spec, $manifest);
            } catch (\Throwable $e) {
                $context->note("El tool {$toolName} no se pudo leer: ".$e->getMessage());

                continue;
            }

            if (($authored['ok'] ?? false) !== true) {
                $context->note("El tool {$toolName} no se pudo leer: ".($authored['error'] ?? 'error desconocido'));

                continue;
            }

            $object = $authored['object'];
            // Keep the draft current so slug uniqueness sees earlier objects.
            $manifest['objects'][] = $object;
            $ops[] = ['op' => 'add', 'path' => '/objects/-', 'value' => $object];
            $summaries[] = $authored['summary'];
            $context->objects[] = $object;
            $context->rowsByObject[$object['id']] = $authored['rows'];

            // Double window: sample the SAME tool one span back so the facts
            // can say "+18% vs periodo anterior" instead of a static number.
            // Best-effort — [] for window-less tools and on any failure.
            try {
                $previous = $this->authoring->previousWindowRows($context->user, $context->integration, $object);
                if ($previous !== []) {
                    $context->previousRowsByObject[$object['id']] = $previous;
                }
            } catch (\Throwable) {
                // The current window already succeeded; deltas are optional.
            }
        }

        if ($ops === []) {
            throw new \RuntimeException('Ninguno de los tools elegidos devolvió datos utilizables.');
        }

        $version = $this->manifests->applyPatch(
            $context->app->fresh(),
            $ops,
            $context->user,
            implode(' · ', $summaries),
        );

        $context->note('Objetos aplicados en la versión v'.$version->version_number.'.');
    }
}
