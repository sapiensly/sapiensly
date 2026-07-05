<?php

namespace App\Services\Express\Phases;

use App\Models\PipelineRun;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\ExpressContext;
use App\Services\Manifest\AppManifestService;

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
        $n = count($context->chosenTools);

        return "📦 Modelando {$n} objeto(s) conectado(s) desde la fuente…";
    }

    public function run(ExpressContext $context, PipelineRun $run): void
    {
        $manifest = $this->manifests->getActiveManifest($context->app);
        if (! is_array($manifest)) {
            throw new \RuntimeException('The app has no active manifest.');
        }

        $ops = [];
        $summaries = [];
        foreach ($context->chosenTools as $toolName) {
            $authored = $this->authoring->author($context->user, $context->integration, [
                'tool_name' => $toolName,
            ], $manifest);

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
